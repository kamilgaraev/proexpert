<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\Models\Organization;
use App\Models\User;
use App\Services\Storage\PersonalFileService;
use RuntimeException;

class AssistantGeneratedReportStorage
{
    private const DIRECTORY = 'reports/assistant';

    public function __construct(private readonly PersonalFileService $personalFiles) {}

    /**
     * @return array{pdf_url: string, filename: string, storage_disk: string, storage_path: string, expires_at: null, size: int}
     */
    public function storePdf(
        string $content,
        string $filename,
        Organization $organization,
        User $user,
        array $sourceRefs = [],
        array $requiredDomains = [],
    ): array {
        $stream = fopen('php://temp', 'w+b');
        if (! is_resource($stream)) {
            throw new RuntimeException('assistant_report_stream_failed');
        }

        try {
            if (fwrite($stream, $content) !== strlen($content) || rewind($stream) !== true) {
                throw new RuntimeException('assistant_report_stream_failed');
            }

            return $this->store($stream, $filename, 'pdf', $organization, $user, $sourceRefs, $requiredDomains);
        } finally {
            fclose($stream);
        }
    }

    public function store($stream, string $filename, string $format, Organization $organization, User $user, array $sourceRefs, array $requiredDomains): array
    {
        $mime = AssistantCustomReportRenderer::MIME_TYPES[$format] ?? throw new \InvalidArgumentException('assistant_report_format_invalid');
        $accessService = app(AssistantReportAccessService::class);
        $accessService->assertReferences($user, (int) $organization->id, $sourceRefs, $requiredDomains);
        $this->personalFiles->createFolder((int) $organization->id, (int) $user->id, 'reports');
        $this->personalFiles->createFolder((int) $organization->id, (int) $user->id, 'assistant', 'reports');
        $personalFile = $this->personalFiles->storeStream((int) $organization->id, (int) $user->id, $stream, basename($filename), $mime, self::DIRECTORY);
        $storageKey = (string) $personalFile->storage_key;

        try {
            $access = $accessService->register($storageKey, $organization, $user, $sourceRefs, $requiredDomains);
        } catch (\Throwable $exception) {
            try {
                $this->personalFiles->delete((string) $personalFile->id, (int) $organization->id, (int) $user->id, self::DIRECTORY);
            } catch (\Throwable $cleanupException) {
                \Illuminate\Support\Facades\Log::error('assistant_report_cleanup_failed', ['organization_id' => $organization->id,
                    'actor_id' => $user->id, 'file_id' => $personalFile->id, 'exception_class' => $cleanupException::class]);
            }
            throw $exception;
        }

        return [
            ...$access,
            ($format === 'pdf' ? 'pdf_url' : ($format === 'xlsx' ? 'excel_url' : 'file_url')) => $access['download_url'],
            'filename' => (string) $personalFile->original_name,
            'storage_disk' => 's3',
            'storage_path' => $storageKey,
            'expires_at' => null,
            'size' => (int) $personalFile->size,
        ];
    }
}
