<?php

namespace fostercommerce\variantmanager\jobs;

use craft\errors\ElementNotFoundException;
use craft\queue\BaseJob;
use craft\web\UploadedFile;
use fostercommerce\variantmanager\Plugin;
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
        Plugin::getInstance()->csv->import($this->filename, $this->csvData, $this->productTypeHandle);
    }

    protected function defaultDescription(): ?string
    {
        return "Importing {$this->filename}";
    }
}
