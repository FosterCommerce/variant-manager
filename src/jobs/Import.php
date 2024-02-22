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
    public string $filename;

    public ?string $productTypeHandle = null;

    public string $csvData;

    public static function fromFile(UploadedFile $uploadedFile, ?string $productTypeHandle): self
    {
        return new self([
            'filename' => $uploadedFile->baseName,
            'productTypeHandle' => $productTypeHandle,
            'csvData' => file_get_contents($uploadedFile->tempName),
        ]);
    }

    public static function fromFilename(string $filename, ?string $productTypeHandle): self
    {
        return new self([
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
        try {
            Plugin::getInstance()->csv->import($this->filename, $this->csvData, $this->productTypeHandle);
        } catch (\Throwable $throwable) {
            Activity::log("Failed to import <strong>{$this->filename}</strong>: {$throwable->getMessage()}", 'error');
            throw $throwable;
        }
    }

    protected function defaultDescription(): ?string
    {
        return "Importing {$this->filename}";
    }
}
