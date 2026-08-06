<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\Models\ImportSession;
use App\Services\Storage\FileService;
use App\Services\Storage\OrganizationStoragePath;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class FileStorageService
{
    private const BASE_DIR = 'estimate-imports';

    public function __construct(
        private FileService $files,
    ) {}

    /**
     * @return array{path: string, name: string, size: int, extension: string}
     */
    public function store(UploadedFile $file, int $organizationId): array
    {
        $fileName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension());
        $storedName = Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');
        $relativePath = OrganizationStoragePath::forOrganization(
            $organizationId,
            self::BASE_DIR."/{$storedName}",
        );
        $realPath = $file->getRealPath();
        if (! is_string($realPath) || ! is_readable($realPath)) {
            throw new RuntimeException('estimate_import_file_unreadable');
        }

        $sha256 = hash_file('sha256', $realPath);
        if (! is_string($sha256)) {
            throw new RuntimeException('estimate_import_file_unreadable');
        }

        $stream = fopen($realPath, 'rb');
        if (! is_resource($stream)) {
            throw new RuntimeException('estimate_import_file_unreadable');
        }

        try {
            $stored = $this->files->putPrivate(
                $relativePath,
                $stream,
                $file->getMimeType() ?: $file->getClientMimeType() ?: 'application/octet-stream',
                $sha256,
            );
        } finally {
            fclose($stream);
        }

        return [
            'path' => $stored->key,
            'name' => $fileName,
            'size' => $stored->sizeBytes,
            'extension' => $extension,
        ];
    }

    /**
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T
     */
    public function withLocalCopy(ImportSession $session, callable $callback): mixed
    {
        $path = $this->downloadToTemporaryPath($session);

        try {
            return $callback($path);
        } finally {
            @unlink($path);
        }
    }

    private function downloadToTemporaryPath(ImportSession $session): string
    {
        if (! $session->file_path) {
            throw new RuntimeException("Import session {$session->id} has no file path");
        }

        if (! $this->files->existsCurrent($session->file_path)) {
            throw new RuntimeException("File \"{$session->file_path}\" does not exist in S3");
        }

        $extension = pathinfo($session->file_path, PATHINFO_EXTENSION);
        $tmpPath = sys_get_temp_dir().'/'.Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');
        $source = $this->files->readCurrent($session->file_path);
        $destination = fopen($tmpPath, 'x+b');
        if (! is_resource($destination)) {
            fclose($source);
            throw new RuntimeException('estimate_import_temporary_file_failed');
        }

        try {
            if (stream_copy_to_stream($source, $destination) === false) {
                throw new RuntimeException('estimate_import_temporary_file_failed');
            }
        } catch (Throwable $exception) {
            @unlink($tmpPath);

            throw $exception;
        } finally {
            fclose($source);
            fclose($destination);
        }

        return $tmpPath;
    }

    public function delete(ImportSession $session): bool
    {
        if (! $session->file_path || ! $this->files->existsCurrent($session->file_path)) {
            return false;
        }

        $this->files->deleteCurrent($session->file_path);

        return true;
    }
}
