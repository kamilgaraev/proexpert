<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime;

use App\Models\ImportSession;
use Generator;

interface RuntimeImportFormatHandlerInterface
{
    public function slug(): string;

    public function label(): string;

    /**
     * @return array<int, string>
     */
    public function supportedExtensions(): array;

    public function detect(ImportSession $session, string $filePath): ImportDetectionResult;

    public function detectStructure(ImportSession $session, string $filePath): ImportStructureResult;

    public function preview(ImportSession $session, string $filePath, ImportStructureResult $structure): ImportPreviewResult;

    public function validate(ImportSession $session, ImportPreviewResult $preview): ImportValidationResult;

    /**
     * @return Generator<int, mixed>
     */
    public function streamRows(ImportSession $session, string $filePath, ImportStructureResult $structure): Generator;
}
