<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\Models\Organization;

interface AssistantReportPdfWriterInterface
{
    /**
     * @param array<string, mixed> $data
     * @return array{pdf_url: string, filename: string, storage_disk: string, storage_path: string, expires_at: string, size: int}
     */
    public function store(string $view, array $data, Organization $organization, string $filenamePrefix): array;
}
