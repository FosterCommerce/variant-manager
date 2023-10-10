<?php

namespace fostercommerce\variantmanager\importers;

use craft\web\UploadedFile;

class JsonImporter extends Importer
{
    public function import(UploadedFile $uploadedFile): void
    {
        throw new \RuntimeException('Importing using a JSON format has not been implemented yet.');
    }
}
