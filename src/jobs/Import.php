<?php

namespace fostercommerce\variantmanager\jobs;

use craft\errors\ElementNotFoundException;
use craft\helpers\Html;
use craft\queue\BaseJob;
use craft\web\UploadedFile;
use fostercommerce\variantmanager\Plugin;
use fostercommerce\variantmanager\records\Activity;
use League\Csv\UnableToProcessCsv;
use yii\base\Exception;
use yii\base\InvalidConfigException;

class Import extends BaseJob
{
	public int $importByUserId;

	public string $filename;

	public ?string $productTypeHandle = null;

	public string $csvData;

	public bool $refreshVariants = false;

	public static function fromFile(UploadedFile $uploadedFile, ?string $productTypeHandle, bool $refreshVariants = false): self
	{
		return new self([
			'importByUserId' => \Craft::$app->getUser()->identity->id,
			'filename' => $uploadedFile->baseName,
			'productTypeHandle' => $productTypeHandle,
			'csvData' => file_get_contents($uploadedFile->tempName),
			'refreshVariants' => $refreshVariants,
		]);
	}

	public static function fromFilename(string $filename, ?string $productTypeHandle, bool $refreshVariants = false): self
	{
		return new self([
			'importByUserId' => \Craft::$app->getUser()->identity->id,
			'filename' => basename($filename),
			'productTypeHandle' => $productTypeHandle,
			'csvData' => file_get_contents($filename),
			'refreshVariants' => $refreshVariants,
		]);
	}

	/**
	 * @throws UnableToProcessCsv
	 * @throws ElementNotFoundException
	 * @throws \Throwable
	 * @throws InvalidConfigException
	 * @throws Exception
	 * @throws \League\Csv\Exception
	 */
	public function execute($queue): void
	{
		$user = \Craft::$app->getUsers()->getUserById($this->importByUserId);
		try {
			$product = Plugin::getInstance()->csv->import($this->filename, $this->csvData, $this->productTypeHandle, $this->refreshVariants);

			// getCpEditUrl() needs the saved product's ID
			$link = Html::a(Html::encode($product->title), (string) $product->getCpEditUrl(), [
				'class' => 'go',
			]);
			$productTypeName = Html::encode($product->type->name);
			$verb = $product->isNewForSite ? 'new' : 'existing';

			Activity::log($user, "Imported {$verb} product {$link} into {$productTypeName}");
		} catch (\Throwable $throwable) {
			// The dashboard renders the message with |raw, and a CSV filename becomes a product title
			Activity::log(
				$user,
				'Failed to import ' . Html::tag('strong', Html::encode($this->filename)) . ': ' . Html::encode($throwable->getMessage()),
				'error'
			);
			throw $throwable;
		}
	}

	protected function defaultDescription(): ?string
	{
		return "Importing {$this->filename}";
	}
}
