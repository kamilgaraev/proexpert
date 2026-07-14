<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Training;

final readonly class DatasetImportUpload
{
    public string $sha256;

    public function __construct(
        public string $role,
        public string $name,
        public string $mimeType,
        public string $body,
    ) {
        if ($body === '') {
            throw new \DomainException('dataset_import_upload_empty');
        }
        $this->sha256 = hash('sha256', $body);
    }
}
