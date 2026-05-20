<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\DTOs\Reports;

final readonly class AssistantReportArtifact
{
    /**
     * @param array<string, mixed> $filters
     */
    public function __construct(
        public string $type,
        public string $filename,
        public string $downloadUrl,
        public string $storageDisk,
        public string $storagePath,
        public ?string $expiresAt,
        public ?string $reportType,
        public array $filters = [],
        public ?string $sourceTool = null,
        public ?string $reportFileId = null
    ) {}

    /**
     * @return array{
     *     type: string,
     *     url: string,
     *     download_url: string,
     *     filename: string,
     *     storage_disk: string,
     *     storage_path: string,
     *     expires_at: string|null,
     *     report_type: string|null,
     *     filters: array<string, mixed>,
     *     source_tool: string|null,
     *     report_file_id: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'url' => $this->downloadUrl,
            'download_url' => $this->downloadUrl,
            'filename' => $this->filename,
            'storage_disk' => $this->storageDisk,
            'storage_path' => $this->storagePath,
            'expires_at' => $this->expiresAt,
            'report_type' => $this->reportType,
            'filters' => $this->filters,
            'source_tool' => $this->sourceTool,
            'report_file_id' => $this->reportFileId,
        ];
    }
}
