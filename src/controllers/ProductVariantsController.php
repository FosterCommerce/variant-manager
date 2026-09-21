<?php

namespace fostercommerce\variantmanager\controllers;

use Craft;
use craft\commerce\elements\Product;
use craft\commerce\models\ProductType;
use craft\commerce\Plugin as CommercePlugin;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\Queue;
use craft\web\Controller;
use craft\web\UploadedFile;
use fostercommerce\variantmanager\errors\FieldMapException;
use fostercommerce\variantmanager\helpers\PermissionHelper;
use fostercommerce\variantmanager\jobs\Import as ImportJob;
use fostercommerce\variantmanager\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

class ProductVariantsController extends Controller
{
	protected array|bool|int $allowAnonymous = [
		'product-exists' => self::ALLOW_ANONYMOUS_NEVER,
		'upload' => self::ALLOW_ANONYMOUS_NEVER,
		'export' => self::ALLOW_ANONYMOUS_NEVER,
	];

	/**
	 * @throws ForbiddenHttpException
	 */
	public function actionProductExists(): Response
	{
		PermissionHelper::requireSaveAnyProductType();

		$productId = explode('__', (string) $this->request->getQueryParam('name'))[0] ?? null;
		if (! ctype_digit((string) $productId)) {
			$productId = null;
		}

		$product = null;
		if ($productId !== null) {
			$product = Product::find()
				->id(Db::escapeParam($productId))
				->status(null)
				->one();

			if ($product === null) {
				throw new NotFoundHttpException(Craft::t('variant-manager', 'import.unknownProductId', [
					'id' => $productId,
				]));
			}

			if (! PermissionHelper::canSaveProductType($product->getType())) {
				throw new ForbiddenHttpException('User not authorized to import into this product type.');
			}
		}

		$productTypes = [];

		foreach (CommercePlugin::getInstance()->productTypes->getAllProductTypes() as $productType) {
			$productTypes[] = [$productType->handle, $productType->name];
		}

		return $this->asJson([
			'exists' => $product !== null,
			'name' => $product?->title,
			'productTypes' => $productTypes,
		]);
	}

	/**
	 * @throws BadRequestHttpException
	 * @throws ForbiddenHttpException
	 * @throws ServerErrorHttpException
	 */
	public function actionUpload(): void
	{
		$this->requirePostRequest();

		PermissionHelper::requireSaveAnyProductType();

		try {
			$uploadedFile = UploadedFile::getInstanceByName('variant-uploads');
			$productTypeHandle = $this->request->getBodyParam('productTypeHandle') ?: null;
			$refreshVariants = $this->request->getBodyParam('refreshVariants') ?: false;

			if (! isset($uploadedFile)) {
				throw new BadRequestHttpException('No file was uploaded');
			}

			$fileType = pathinfo($uploadedFile->name, PATHINFO_EXTENSION);
			if ($fileType === 'zip') {
				$this->queueZipImports($uploadedFile, $productTypeHandle, $refreshVariants);
			} elseif ($fileType === 'csv') {
				$this->requireImportPermission($uploadedFile->name, $productTypeHandle);
				Queue::push(
					ImportJob::fromFile($uploadedFile, $productTypeHandle, $refreshVariants),
					queue: Plugin::getInstance()->queue,
				);
			} else {
				$this->setFailFlash("{$uploadedFile->name} is not a valid file type");
				return;
			}
		} catch (ForbiddenHttpException $forbiddenHttpException) {
			throw $forbiddenHttpException;
		} catch (\Exception $e) {
			$this->setFailFlash($e->getMessage());
			return;
		}

		$this->setSuccessFlash("File {$uploadedFile->name} has been queued for processing");
	}

	/**
	 * @throws \JsonException
	 * @throws NotFoundHttpException
	 * @throws ServerErrorHttpException
	 * @throws BadRequestHttpException
	 */
	public function actionExport(): void
	{
		$ids = $this->request->getRequiredQueryParam('ids');

		$this->requirePermission('variant-manager:export');

		$download = filter_var(
			$this->request->getQueryParam('download', false),
			FILTER_VALIDATE_BOOLEAN,
			FILTER_NULL_ON_FAILURE
		);

		$csvService = Plugin::getInstance()->getCsv();
		$results = [];
		foreach (explode('|', (string) $ids) as $id) {
			try {
				$result = $csvService->export($id);
			} catch (FieldMapException $fieldMapException) {
				// Craft renders the message only for a UserException, and this one names the setting to fix
				throw new ServerErrorHttpException($fieldMapException->getMessage(), 0, $fieldMapException);
			}

			if ($result === false) {
				throw new NotFoundHttpException("Product with ID {$id} not found");
			}

			$results[] = $result;
		}

		if ($download) {
			if (count($results) === 1) {
				$result = $results[0];
				$filename = "{$result['filename']}.csv";
				$result = $result['export'];
				if (is_array($result)) {
					$result = json_encode($result, JSON_THROW_ON_ERROR);
				}

				$this->response->sendContentAsFile($result, $filename, [
					'mimeType' => 'text/csv',
				]);
			} else {
				$zipPath = tempnam(sys_get_temp_dir(), 'export_');
				$zipArchive = new \ZipArchive();
				if ($zipArchive->open($zipPath, \ZipArchive::CREATE) !== true) {
					throw new \RuntimeException('Unable to create zip archive');
				}

				foreach ($results as $result) {
					$filename = "{$result['filename']}.csv";
					$result = $result['export'];
					if (is_array($result)) {
						$result = json_encode($result, JSON_THROW_ON_ERROR);
					}

					$zipArchive->addFromString($filename, $result);
				}

				$zipArchive->close();

				$attachmentName = (new \DateTime())->format('YmdHis');
				$this->response->sendContentAsFile(file_get_contents($zipPath), "products_{$attachmentName}.zip");
				FileHelper::unlink($zipPath);
			}
		} else {
			$this->response->format = Response::FORMAT_JSON;
			$this->response->data = array_map(static fn ($r) => $r['export'], $results);
		}
	}

	/**
	 * @throws BadRequestHttpException
	 * @throws ForbiddenHttpException
	 */
	private function queueZipImports(UploadedFile $uploadedFile, ?string $productTypeHandle, bool $refreshVariants): void
	{
		$zip = new \ZipArchive();

		if ($zip->open($uploadedFile->tempName) !== true) {
			throw new BadRequestHttpException(Craft::t('variant-manager', 'import.unreadableZip'));
		}

		$filenames = [];

		for ($i = 0; $i < $zip->numFiles; ++$i) {
			$filename = $zip->getNameIndex($i);
			$pathinfo = pathinfo($filename);

			// Skip dotfiles and __MACOSX entries so only real CSVs are extracted
			if (! str_starts_with($pathinfo['filename'], '.') && ($pathinfo['extension'] ?? null) === 'csv') {
				$filenames[] = $filename;
			}
		}

		// Authorize the whole zip before queueing any of it, or a refusal arrives after earlier files have run
		foreach ($filenames as $filename) {
			$this->requireImportPermission($filename, $productTypeHandle);
		}

		$extractToDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'variant-manager';
		$zip->extractTo($extractToDir, $filenames);

		foreach ($filenames as $filename) {
			$file = $extractToDir . DIRECTORY_SEPARATOR . $filename;
			Queue::push(
				ImportJob::fromFilename($file, $productTypeHandle, $refreshVariants),
				queue: Plugin::getInstance()->queue,
			);
			unlink($file);
		}
	}

	/**
	 * A file writes to the product its id prefix names, or to the product type the post names on a create.
	 *
	 * Falls back to the store-wide check where neither identifies a product type.
	 *
	 * @throws ForbiddenHttpException
	 */
	private function requireImportPermission(string $filename, ?string $productTypeHandle): void
	{
		$productId = explode('__', basename($filename))[0];

		if (ctype_digit($productId)) {
			/** @var Product|null $product */
			$product = Product::find()->id((int) $productId)->status(null)->one();
			$productType = $product?->getType();
		} else {
			$productType = $productTypeHandle === null
				? null
				: CommercePlugin::getInstance()->getProductTypes()->getProductTypeByHandle($productTypeHandle);
		}

		$allowed = $productType instanceof ProductType
			? PermissionHelper::canSaveProductType($productType)
			: PermissionHelper::canSaveAnyProductType();

		if (! $allowed) {
			throw new ForbiddenHttpException('User not authorized to import into this product type.');
		}
	}
}
