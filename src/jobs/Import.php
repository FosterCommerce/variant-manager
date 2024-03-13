<?php

namespace fostercommerce\variantmanager\jobs;

use craft\errors\ElementNotFoundException;
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

    public static function fromFile(UploadedFile $uploadedFile, ?string $productTypeHandle): self
    {
        return new self([
            'importByUserId' => \Craft::$app->getUser()->identity->id,
            'filename' => $uploadedFile->baseName,
            'productTypeHandle' => $productTypeHandle,
            'csvData' => file_get_contents($uploadedFile->tempName),
        ]);
    }

    public static function fromFilename(string $filename, ?string $productTypeHandle): self
    {
        return new self([
            'importByUserId' => \Craft::$app->getUser()->identity->id,
            'filename' => basename($filename),
            'productTypeHandle' => $productTypeHandle,
            'csvData' => file_get_contents($filename),
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
            $product = Plugin::getInstance()->csv->import($this->filename, $this->csvData, $this->productTypeHandle);

            // Do this after save so that we can get the correct edit URL from a new product
            if ($product->isNewForSite) {
                Activity::log(
                    $user,
                    "Imported new product <a class=\"go\" href=\"{$product->getCpEditUrl()}\">{$product->title}</a> into {$product->type->name}",
                );
            } else {
                Activity::log(
                    $user,
                    "Imported existing product <a class=\"go\" href=\"{$product->getCpEditUrl()}\">{$product->title}</a> into {$product->type->name}",
                );
            }
        } catch (\Throwable $throwable) {
            Activity::log(
                $user,
                "Failed to import <strong>{$this->filename}</strong>: {$throwable->getMessage()}", 'error'
            );
            dd($throwable);
            throw $throwable;
        }
    }

    protected function defaultDescription(): ?string
    {
        return "Importing {$this->filename}";
    }
}
