<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\DTOs\Reports\AssistantReportArtifact;
use App\Models\Organization;
use App\Models\ReportFile;
use App\Models\User;
use App\Services\Storage\FileService;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

final readonly class AssistantReportFileService
{
    private const URL_KEYS = [
        'pdf_url' => 'pdf',
        'excel_url' => 'excel',
        'download_url' => 'file',
        'file_url' => 'file',
    ];

    public function __construct(
        private FileService $fileService,
        private AssistantReportCatalog $reportCatalog = new AssistantReportCatalog
    ) {}

    /**
     * @param array<string, mixed> $arguments
     * @return array<int, array<string, mixed>>
     */
    public function artifactsFromToolResult(
        string $toolName,
        mixed $toolResult,
        Organization $organization,
        ?User $user,
        array $arguments = []
    ): array {
        if (! is_array($toolResult)) {
            return [];
        }

        $artifact = $this->artifactFromArray($toolName, $toolResult, $organization, $user, $arguments);

        return $artifact instanceof AssistantReportArtifact ? [$artifact->toArray()] : [];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $arguments
     */
    private function artifactFromArray(
        string $toolName,
        array $data,
        Organization $organization,
        ?User $user,
        array $arguments
    ): ?AssistantReportArtifact {
        $storageDisk = $this->optionalString($data['storage_disk'] ?? null);
        $storagePath = $this->optionalString($data['storage_path'] ?? null);

        if ($storageDisk !== 's3' || $storagePath === null || ! $this->belongsToOrganizationReports($storagePath, $organization)) {
            return null;
        }

        $urlKey = $this->firstUrlKey($data);
        if ($urlKey === null) {
            return null;
        }

        $filename = $this->filename($data, $storagePath);
        $expiresAt = $this->expiresAt($data);
        $definition = $this->reportCatalog->findByToolName($toolName);
        $type = self::URL_KEYS[$urlKey] ?? $definition?->artifactType ?? 'file';
        $downloadUrl = $this->fileService->temporaryUrl($storagePath, 24 * 60, $organization)
            ?? $this->optionalString($data[$urlKey] ?? null);

        if ($downloadUrl === null || $downloadUrl === '') {
            return null;
        }

        $reportFile = ReportFile::query()->updateOrCreate(
            [
                'path' => $storagePath,
                'organization_id' => $organization->id,
            ],
            [
                'type' => 'reports',
                'filename' => $filename,
                'name' => $filename,
                'size' => $this->optionalInt($data['size'] ?? null) ?? 0,
                'expires_at' => $expiresAt,
                'user_id' => $user?->id,
            ]
        );

        return new AssistantReportArtifact(
            type: $type,
            filename: $filename,
            downloadUrl: $downloadUrl,
            storageDisk: $storageDisk,
            storagePath: $storagePath,
            expiresAt: $expiresAt,
            reportType: $definition?->id,
            filters: $this->filters($arguments),
            sourceTool: $toolName,
            reportFileId: (string) $reportFile->id
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function firstUrlKey(array $data): ?string
    {
        foreach (array_keys(self::URL_KEYS) as $key) {
            if (is_string($data[$key] ?? null) && trim((string) $data[$key]) !== '') {
                return $key;
            }
        }

        return null;
    }

    private function belongsToOrganizationReports(string $path, Organization $organization): bool
    {
        return str_starts_with($path, 'org-'.((int) $organization->id).'/reports/');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function filename(array $data, string $storagePath): string
    {
        $filename = $this->optionalString($data['filename'] ?? null);
        if ($filename !== null && $filename !== '') {
            return basename($filename);
        }

        $basename = basename($storagePath);

        return $basename !== '' ? $basename : Str::uuid()->toString();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function expiresAt(array $data): ?string
    {
        $value = $data['expires_at'] ?? null;

        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        return $this->optionalString($value);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function filters(array $arguments): array
    {
        return array_filter(
            $arguments,
            static fn (mixed $value): bool => $value !== null && $value !== ''
        );
    }

    private function optionalString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return null;
    }

    private function optionalInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}
