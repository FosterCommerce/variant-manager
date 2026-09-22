<?php

namespace fostercommerce\variantmanager\jobs;

use Craft;
use craft\elements\User;
use craft\errors\ElementNotFoundException;
use craft\helpers\Html;
use craft\queue\BaseJob;
use craft\web\UploadedFile;
use fostercommerce\variantmanager\errors\FieldMapException;
use fostercommerce\variantmanager\errors\ImportDataException;
use fostercommerce\variantmanager\Plugin;
use fostercommerce\variantmanager\records\Activity;
use League\Csv\Exception as CsvException;
use Throwable;
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
			'importByUserId' => self::currentUserId(),
			'filename' => $uploadedFile->baseName,
			'productTypeHandle' => $productTypeHandle,
			'csvData' => file_get_contents($uploadedFile->tempName),
			'refreshVariants' => $refreshVariants,
		]);
	}

	public static function fromFilename(string $filename, ?string $productTypeHandle, bool $refreshVariants = false): self
	{
		return new self([
			'importByUserId' => self::currentUserId(),
			'filename' => basename($filename),
			'productTypeHandle' => $productTypeHandle,
			'csvData' => file_get_contents($filename),
			'refreshVariants' => $refreshVariants,
		]);
	}

	/**
	 * @throws ElementNotFoundException
	 * @throws InvalidConfigException
	 * @throws Exception
	 * @throws Throwable
	 */
	public function execute($queue): void
	{
		$user = Craft::$app->getUsers()->getUserById($this->importByUserId);
		try {
			$product = Plugin::getInstance()->getCsv()->import($this->filename, $this->csvData, $this->productTypeHandle, $this->refreshVariants);

			// getCpEditUrl() needs the saved product's ID
			$link = Html::a(Html::encode((string) $product->title), (string) $product->getCpEditUrl(), [
				'class' => 'go',
			]);
			$productTypeName = Html::encode((string) $product->getType()->name);
			$verb = $product->isNewForSite ? 'new' : 'existing';

			Activity::log($user, "Imported {$verb} product {$link} into {$productTypeName}");
		} catch (CsvException | FieldMapException | ImportDataException $dataFailure) {
			// Do not rethrow. The same file and the same settings produce the same error.
			$this->logFailure($user, $dataFailure);
		} catch (Throwable $throwable) {
			$this->logFailure($user, $throwable);

			throw $throwable;
		}
	}

	protected function defaultDescription(): ?string
	{
		return "Importing {$this->filename}";
	}

	private function logFailure(?User $user, Throwable $throwable): void
	{
		// The dashboard renders the message with |raw, and a CSV filename becomes a product title
		Activity::log(
			$user,
			'Failed to import ' . Html::tag('strong', Html::encode($this->filename)) . ': ' . Html::encode($throwable->getMessage()),
			'error'
		);

		// A job that ends rather than fails does not write a Craft log of its own
		Craft::warning("Import failed for {$this->filename}: {$throwable->getMessage()}", __METHOD__);
	}

	private static function currentUserId(): int
	{
		return (int) Craft::$app->getUser()->getIdentity()?->id;
	}
}
