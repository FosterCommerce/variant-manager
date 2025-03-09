<?php

namespace fostercommerce\variantmanager\controllers;

use craft\commerce\elements\Product;
use craft\commerce\Plugin as CommercePlugin;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\Queue;
use craft\web\Controller;
use craft\web\UploadedFile;
use fostercommerce\variantmanager\jobs\Import as ImportJob;
use fostercommerce\variantmanager\Plugin;
use yii\base\InvalidConfigException;
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
		$this->requirePermission('variant-manager:import');

		$productId = explode('__', (string) $this->request->getQueryParam('name'))[0] ?? null;
		if (! ctype_digit((string) $productId)) {
			$productId = null;
		}

		$product = null;
		if ($productId !== null) {
			$product = Product::find()
				->id(Db::escapeParam($productId))
				->one();

			if ($product === null) {
				throw new \RuntimeException('Invalid product ID');
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

		$this->requirePermission('variant-manager:import');

		try {
			$uploadedFile = UploadedFile::getInstanceByName('variant-uploads');
			$productTypeHandle = $this->request->getBodyParam('productTypeHandle') ?: null;
			$refreshVariants = $this->request->getBodyParam('refreshVariants') ?: false;

			if (! isset($uploadedFile)) {
				throw new BadRequestHttpException('No file was uploaded');
			}

			$fileType = pathinfo($uploadedFile->name, PATHINFO_EXTENSION);
			if ($fileType === 'zip') {
				$zip = new \ZipArchive();
				$zip->open($uploadedFile->tempName);
				$filenames = [];
				for ($i = 0; $i < $zip->numFiles; ++$i) {
					$filename = $zip->getNameIndex($i);
					$pathinfo = pathinfo($filename);
					$baseName = $pathinfo['filename'];
					$extension = $pathinfo['extension'] ?? null;
					if (! str_starts_with($baseName, '.') && $extension === 'csv') {
						// Only extract csv files from the zip.
						// Don't extract any hidden files. This helps catch OSX specific files such as DS_Store, etc. It
						// also prevents extracting files from the __MACOSX dir.
						$filenames[] = $filename;
					}
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
			} elseif ($fileType === 'csv') {
				Queue::push(
					ImportJob::fromFile($uploadedFile, $productTypeHandle, $refreshVariants),
					queue: Plugin::getInstance()->queue,
				);
			} else {
				$this->setFailFlash("{$uploadedFile->name} is not a valid file type");
				return;
			}
		} catch (\Exception $e) {
			$this->setFailFlash($e->getMessage());
			return;
		}

		$this->setSuccessFlash("File {$uploadedFile->name} has been queued for processing");
	}

	/**
	 * @throws \JsonException
	 * @throws NotFoundHttpException
	 * @throws InvalidConfigException
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

		$csvService = Plugin::getInstance()->csv;
		$results = [];
		foreach (explode('|', (string) $ids) as $id) {
			$result = $csvService->export($id);

			if ($result === false) {
				throw new NotFoundHttpException("Product with ID {$id} not found");
			}

			$results[] = $result;
		}

		if ($download) {
			if (count($results) === 1) {
				// If there is just a single product, then download that file
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
				// If there are multiple products then download a zip file of the content
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
}
