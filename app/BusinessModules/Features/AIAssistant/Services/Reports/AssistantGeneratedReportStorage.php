<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\Models\Organization;
use App\Models\User;
use App\Services\Storage\FileService;
use App\Services\Storage\PersonalFileService;
use RuntimeException;

class AssistantGeneratedReportStorage
{
    private const DIRECTORY = 'reports/assistant';

    public function __construct(
        private readonly PersonalFileService $personalFiles,
        private readonly FileService $files,
    ) {}

    /**
     * @return array{pdf_url: string, filename: string, storage_disk: string, storage_path: string, expires_at: null, size: int}
     */
    public function storePdf(
        string $content,
        string $filename,
        Organization $organization,
        User $user,
    ): array {
        $stream = fopen('php://temp', 'w+b');
        if (! is_resource($stream)) {
            throw new RuntimeException('assistant_report_stream_failed');
        }

        try {
            if (fwrite($stream, $content) !== strlen($content) || rewind($stream) !== true) {
                throw new RuntimeException('assistant_report_stream_failed');
            }

            $personalFile = $this->personalFiles->storeStream(
                (int) $organization->id,
                (int) $user->id,
                $stream,
                basename($filename),
                'application/pdf',
                self::DIRECTORY,
            );
        } finally {
            fclose($stream);
        }

        $storageKey = (string) $personalFile->storage_key;

        return [
            'pdf_url' => $this->files->temporaryDownloadUrl(
                $storageKey,
                (int) config('filesystems.s3.download_ttl_seconds'),
            ),
            'filename' => (string) $personalFile->original_name,
            'storage_disk' => 's3',
            'storage_path' => $storageKey,
            'expires_at' => null,
            'size' => (int) $personalFile->size,
        ];
    }
}
