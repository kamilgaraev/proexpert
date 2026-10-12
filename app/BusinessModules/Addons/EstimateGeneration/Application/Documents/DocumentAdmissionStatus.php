<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

final readonly class DocumentAdmissionStatus
{
    public function __construct(public string $requestId, public string $status, public ?UploadDocumentsResult $result = null) {}
}
