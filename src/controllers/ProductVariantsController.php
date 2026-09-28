<?php

namespace fostercommerce\variantmanager\controllers;

use Craft;
use craft\commerce\elements\Product;
use craft\commerce\models\ProductType;
use craft\commerce\Plugin as CommercePlugin;
use craft\elements\User;
use craft\helpers\FileHelper;
use craft\helpers\Queue;
use craft\web\Controller;
use craft\web\UploadedFile;
use fostercommerce\variantmanager\errors\FieldMapException;
use fostercommerce\variantmanager\helpers\PermissionHelper;
use fostercommerce\variantmanager\jobs\Import as ImportJob;
use fostercommerce\variantmanager\Plugin;
use fostercommerce\variantmanager\services\Csv;
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

		/** @var string|null $uploadName */
		$uploadName = $this->request->getQueryParam('name');
		$productId = Csv::productIdFromFilename((string) $uploadName);

		$product = null;
		if ($productId !== null) {
			$product = Product::find()
				->id($productId)
				->status(null)
				->one();

			if (! $product instanceof Product) {
				throw new NotFoundHttpException(Craft::t('variant-manager', 'import.unknownProductId', [
					'id' => $productId,
				]));
			}

			if (! Craft::$app->getElements()->canSaveCanonical($product)) {
				throw new ForbiddenHttpException('User not authorized to import into this product type.');
			}
		}

		$productTypes = [];

		/** @var CommercePlugin $commerce */
		$commerce = CommercePlugin::getInstance();

		// List only the product types the user can create a product in
		foreach ($commerce->getProductTypes()->getAllProductTypes() as $productType) {
			if (Craft::$app->getElements()->canSaveCanonical($this->newProduct($productType))) {
				$productTypes[] = [$productType->handle, $productType->name];
			}
		}

		return $this->asJson([
			'exists' => $product instanceof Product,
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
			/** @var string|null $productTypeHandle */
			$productTypeHandle = $this->request->getBodyParam('productTypeHandle') ?: null;
			$refreshVariants = (bool) $this->request->getBodyParam('refreshVariants');

			if (! isset($uploadedFile)) {
				throw new BadRequestHttpException('No file was uploaded');
			}

			if ($uploadedFile->getHasError()) {
				throw new BadRequestHttpException(Craft::t('variant-manager', 'import.uploadFailed'));
			}

			$fileType = strtolower(pathinfo($uploadedFile->name, PATHINFO_EXTENSION));
			if ($fileType === 'zip') {
				$this->queueZipImports($uploadedFile, $productTypeHandle, $refreshVariants);
			} elseif ($fileType === 'csv') {
				$this->requireImportPermission($uploadedFile->name, $productTypeHandle);
				Queue::push(
					ImportJob::fromFile($uploadedFile, $productTypeHandle, $refreshVariants),
					queue: Plugin::getInstance()->getQueue(),
				);
			} else {
				$this->setFailFlash("{$uploadedFile->name} is not a valid file type");
				return;
			}
		} catch (ForbiddenHttpException $forbiddenHttpException) {
			throw $forbiddenHttpException;
		} catch (\Exception $exception) {
			$this->setFailFlash($exception->getMessage());
			return;
		}

		$this->setSuccessFlash("File {$uploadedFile->name} has been queued for processing");
	}

	/**
	 * @throws NotFoundHttpException
	 * @throws ServerErrorHttpException
	 * @throws BadRequestHttpException
	 * @throws ForbiddenHttpException
	 */
	public function actionExport(): void
	{
		/** @var string $ids */
		$ids = $this->request->getRequiredQueryParam('ids');

		$this->requirePermission('variant-manager:export');

		$download = filter_var(
			$this->request->getQueryParam('download', false),
			FILTER_VALIDATE_BOOLEAN,
			FILTER_NULL_ON_FAILURE
		);

		$csvService = Plugin::getInstance()->getCsv();
		$results = [];
		/** @var User $currentUser */
		$currentUser = static::currentUser();

		foreach (explode('|', $ids) as $id) {
			// Export disabled products and variants too
			/** @var Product|null $product */
			$product = Product::find()->id((int) $id)->status(null)->one();

			if (! $product instanceof Product) {
				throw new NotFoundHttpException("Product with ID {$id} not found");
			}

			// Export only products the user can view, because the file holds prices, inventory and custom fields
			if (! Craft::$app->getElements()->canView($product, $currentUser)) {
				throw new ForbiddenHttpException('User not authorized to export this product.');
			}

			try {
				$results[] = $csvService->export($product);
			} catch (FieldMapException $fieldMapException) {
				// Craft renders the message only for a UserException, and this one names the setting to fix
				throw new ServerErrorHttpException($fieldMapException->getMessage(), 0, $fieldMapException);
			}
		}

		if ($download) {
			if (count($results) === 1) {
				$result = $results[0];
				$this->response->sendContentAsFile($result['export'], "{$result['filename']}.csv", [
					'mimeType' => 'text/csv',
				]);
			} else {
				$zipPath = tempnam(sys_get_temp_dir(), 'export_');
				$zipArchive = new \ZipArchive();
				if ($zipArchive->open($zipPath, \ZipArchive::CREATE) !== true) {
					throw new \RuntimeException('Unable to create zip archive');
				}

				foreach ($results as $result) {
					$zipArchive->addFromString("{$result['filename']}.csv", $result['export']);
				}

				$zipArchive->close();

				$attachmentName = (new \DateTime())->format('YmdHis');
				$this->response->sendContentAsFile((string) file_get_contents($zipPath), "products_{$attachmentName}.zip");
				FileHelper::unlink($zipPath);
			}
		} else {
			$this->response->format = Response::FORMAT_JSON;
			$this->response->data = array_map(static fn (array $result): mixed => $result['export'], $results);
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
			$filename = (string) $zip->getNameIndex($i);
			$pathinfo = pathinfo($filename);

			// Skip dotfiles and __MACOSX entries so only real CSVs are queued
			if (! str_starts_with($pathinfo['filename'], '.') && strtolower($pathinfo['extension'] ?? '') === 'csv') {
				$filenames[] = $filename;
			}
		}

		// Authorize the whole zip before queueing any of it, or a refusal arrives after earlier files have run
		foreach ($filenames as $filename) {
			$this->requireImportPermission($filename, $productTypeHandle);
		}

		// Read entries rather than extract them, because an entry name can contain `../`
		$csvDataByFilename = [];

		foreach ($filenames as $filename) {
			$csvData = $zip->getFromName($filename);

			if ($csvData === false) {
				throw new BadRequestHttpException(Craft::t('variant-manager', 'import.unreadableZip'));
			}

			$csvDataByFilename[$filename] = $csvData;
		}

		foreach ($csvDataByFilename as $filename => $csvData) {
			Queue::push(
				ImportJob::fromCsvData($filename, $csvData, $productTypeHandle, $refreshVariants),
				queue: Plugin::getInstance()->getQueue(),
			);
		}
	}

	/**
	 * Ask Craft whether the user can save the product a file writes to, the way the product's edit page does.
	 *
	 * @throws BadRequestHttpException
	 * @throws ForbiddenHttpException
	 */
	private function requireImportPermission(string $filename, ?string $productTypeHandle): void
	{
		$productId = Csv::productIdFromFilename($filename);

		if ($productId !== null) {
			$product = Product::find()->id($productId)->status(null)->one();

			if (! $product instanceof Product) {
				throw new BadRequestHttpException(Craft::t('variant-manager', 'import.unknownProductId', [
					'id' => $productId,
				]));
			}
		} else {
			/** @var CommercePlugin $commerce */
			$commerce = CommercePlugin::getInstance();
			$productType = $productTypeHandle === null
				? null
				: $commerce->getProductTypes()->getProductTypeByHandle($productTypeHandle);

			if (! $productType instanceof ProductType) {
				throw new BadRequestHttpException(Craft::t('variant-manager', 'import.invalidProductTypeHandle'));
			}

			$product = $this->newProduct($productType);
		}

		if (! Craft::$app->getElements()->canSaveCanonical($product)) {
			throw new ForbiddenHttpException('User not authorized to import into this product type.');
		}
	}

	private function newProduct(ProductType $productType): Product
	{
		return new Product([
			'typeId' => $productType->id,
			'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
		]);
	}
}
