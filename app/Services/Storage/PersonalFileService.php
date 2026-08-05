<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\PersonalFile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class PersonalFileService
{
    private const DOWNLOAD_TTL_SECONDS = 300;

    private const MIME_EXTENSIONS = [
        'application/json' => 'json',
        'application/msword' => 'doc',
        'application/octet-stream' => 'bin',
        'application/pdf' => 'pdf',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/zip' => 'zip',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'text/csv' => 'csv',
        'text/plain' => 'txt',
    ];

    public function __construct(private readonly FileService $files) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, PersonalFile>
     */
    public function paginate(
        int $organizationId,
        int $userId,
        array $filters,
        ?string $directoryScope = null,
    ): LengthAwarePaginator {
        $query = $this->ownedQuery($organizationId, $userId);

        if ($directoryScope !== null) {
            $scope = $this->normalizeDirectory($directoryScope);
            $query
                ->where('is_folder', false)
                ->where(static function (Builder $query) use ($scope): void {
                    $query->where('directory', $scope)
                        ->orWhere('directory', 'like', self::likeDescendants($scope));
                });
        } else {
            $directory = $this->normalizeDirectory((string) ($filters['folder'] ?? ''));
            $query->where('directory', $directory);
        }

        if (is_string($filters['filename'] ?? null) && $filters['filename'] !== '') {
            $query->where('original_name', 'like', '%'.$filters['filename'].'%');
        }
        if (is_string($filters['date_from'] ?? null) && $filters['date_from'] !== '') {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (is_string($filters['date_to'] ?? null) && $filters['date_to'] !== '') {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        $sortBy = match ($filters['sort_by'] ?? 'created_at') {
            'filename' => 'original_name',
            'size' => 'size',
            default => 'created_at',
        };
        $sortDirection = ($filters['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 15)));

        return $query->orderBy($sortBy, $sortDirection)->paginate($perPage);
    }

    public function createFolder(
        int $organizationId,
        int $userId,
        string $name,
        string $parentDirectory = '',
    ): PersonalFile {
        $directory = $this->normalizeDirectory($parentDirectory);
        $name = $this->normalizeFolderName($name);

        return PersonalFile::query()->firstOrCreate([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'directory' => $directory,
            'original_name' => $name,
            'is_folder' => true,
        ], [
            'storage_key' => null,
            'mime_type' => null,
            'sha256' => null,
            'size' => 0,
        ]);
    }

    public function upload(
        int $organizationId,
        int $userId,
        UploadedFile $uploadedFile,
        string $directory = '',
    ): PersonalFile {
        $path = $uploadedFile->getRealPath();
        $stream = is_string($path) ? fopen($path, 'rb') : false;
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('personal_file_upload_invalid');
        }

        try {
            return $this->storeStream(
                $organizationId,
                $userId,
                $stream,
                $uploadedFile->getClientOriginalName(),
                $uploadedFile->getMimeType() ?: 'application/octet-stream',
                $directory,
            );
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $source */
    public function storeStream(
        int $organizationId,
        int $userId,
        $source,
        string $originalName,
        string $mime,
        string $directory = '',
    ): PersonalFile {
        if (! is_resource($source) || get_resource_type($source) !== 'stream') {
            throw new InvalidArgumentException('personal_file_upload_invalid');
        }

        $directory = $this->normalizeDirectory($directory);
        $originalName = $this->normalizeName($originalName);
        $mime = $this->normalizeMime($mime);
        $temporary = fopen('php://temp', 'w+b');
        if (! is_resource($temporary)) {
            throw new \RuntimeException('personal_file_upload_failed');
        }

        $hash = hash_init('sha256');
        try {
            while (! feof($source)) {
                $chunk = fread($source, 1024 * 1024);
                if ($chunk === false || fwrite($temporary, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('personal_file_upload_failed');
                }
                hash_update($hash, $chunk);
            }
            if (ftell($temporary) === 0 || rewind($temporary) !== true) {
                throw new InvalidArgumentException('personal_file_upload_empty');
            }

            $sha256 = hash_final($hash);
            $key = OrganizationStoragePath::personal(
                $organizationId,
                $userId,
                (string) Str::uuid(),
                $this->extensionForMime($mime),
            );
            $stored = $this->files->putPrivate($key, $temporary, $mime, $sha256);

            try {
                return PersonalFile::query()->create([
                    'organization_id' => $organizationId,
                    'user_id' => $userId,
                    'storage_key' => $stored->key,
                    'directory' => $directory,
                    'original_name' => $originalName,
                    'mime_type' => $stored->mime,
                    'sha256' => $stored->sha256,
                    'size' => $stored->sizeBytes,
                    'is_folder' => false,
                ]);
            } catch (Throwable $exception) {
                $this->files->deleteCurrent($stored->key);

                throw $exception;
            }
        } finally {
            fclose($temporary);
        }
    }

    public function findOwned(
        string $id,
        int $organizationId,
        int $userId,
        ?string $directoryScope = null,
    ): PersonalFile {
        $query = $this->ownedQuery($organizationId, $userId)->whereKey($id);
        if ($directoryScope !== null) {
            $scope = $this->normalizeDirectory($directoryScope);
            $query
                ->where('is_folder', false)
                ->where(static function (Builder $query) use ($scope): void {
                    $query->where('directory', $scope)
                        ->orWhere('directory', 'like', self::likeDescendants($scope));
                });
        }

        return $query->firstOrFail();
    }

    /** @return resource */
    public function read(PersonalFile $file)
    {
        if ($file->is_folder || ! is_string($file->storage_key) || $file->storage_key === '') {
            throw new InvalidArgumentException('personal_file_not_readable');
        }

        return $this->files->readCurrent($file->storage_key);
    }

    public function delete(string $id, int $organizationId, int $userId, ?string $directoryScope = null): void
    {
        $file = $this->findOwned($id, $organizationId, $userId, $directoryScope);
        if (! $file->is_folder) {
            $this->deleteObject($file);
            $file->delete();

            return;
        }

        $folderDirectory = $this->logicalPath($file);
        $nested = $this->ownedQuery($organizationId, $userId)
            ->where(static function (Builder $query) use ($folderDirectory): void {
                $query->where('directory', $folderDirectory)
                    ->orWhere('directory', 'like', self::likeDescendants($folderDirectory));
            })
            ->get();

        foreach ($nested as $nestedFile) {
            if (! $nestedFile->is_folder) {
                $this->deleteObject($nestedFile);
            }
        }

        PersonalFile::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->whereIn('id', $nested->pluck('id')->push($file->id)->all())
            ->delete();
    }

    /** @return array<string, mixed> */
    public function payload(PersonalFile $file, bool $withDownloadUrl = true): array
    {
        $payload = $file->toArray();
        unset($payload['storage_key']);
        $payload['filename'] = $file->original_name;
        $payload['path'] = $this->logicalPath($file);
        $payload['download_url'] = null;

        if ($withDownloadUrl && ! $file->is_folder && is_string($file->storage_key)) {
            try {
                $payload['download_url'] = $this->files->temporaryDownloadUrl(
                    $file->storage_key,
                    (int) config('filesystems.s3.download_ttl_seconds', self::DOWNLOAD_TTL_SECONDS),
                );
            } catch (Throwable) {
                Log::warning('personal_file_temporary_url_failed', ['file_id' => $file->id]);
            }
        }

        return $payload;
    }

    /** @return Builder<PersonalFile> */
    private function ownedQuery(int $organizationId, int $userId): Builder
    {
        if ($organizationId < 1 || $userId < 1) {
            throw new InvalidArgumentException('personal_file_scope_invalid');
        }

        return PersonalFile::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId);
    }

    private function deleteObject(PersonalFile $file): void
    {
        if (is_string($file->storage_key) && $file->storage_key !== '') {
            $this->files->deleteCurrent($file->storage_key);
        }
    }

    private function logicalPath(PersonalFile $file): string
    {
        return ltrim($file->directory.'/'.$file->original_name, '/');
    }

    private function normalizeDirectory(string $directory): string
    {
        if (str_contains($directory, '\\') || preg_match('/[\x00-\x1F\x7F]/', $directory) === 1) {
            throw new InvalidArgumentException('personal_file_directory_invalid');
        }

        $directory = trim($directory, '/');
        if ($directory === '') {
            return '';
        }

        $segments = explode('/', $directory);
        if (count($segments) > 16) {
            throw new InvalidArgumentException('personal_file_directory_invalid');
        }
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || mb_strlen($segment) > 120) {
                throw new InvalidArgumentException('personal_file_directory_invalid');
            }
        }

        return implode('/', $segments);
    }

    private function normalizeName(string $name): string
    {
        $name = trim(str_replace('\\', '/', $name));
        $name = basename($name);
        if (
            $name === ''
            || $name === '.'
            || $name === '..'
            || mb_strlen($name) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
        ) {
            throw new InvalidArgumentException('personal_file_name_invalid');
        }

        return $name;
    }

    private function normalizeFolderName(string $name): string
    {
        if (str_contains($name, '/') || str_contains($name, '\\')) {
            throw new InvalidArgumentException('personal_file_name_invalid');
        }

        return $this->normalizeName($name);
    }

    private function normalizeMime(string $mime): string
    {
        $mime = strtolower(trim(explode(';', $mime, 2)[0]));

        return array_key_exists($mime, self::MIME_EXTENSIONS)
            ? $mime
            : 'application/octet-stream';
    }

    private function extensionForMime(string $mime): string
    {
        return self::MIME_EXTENSIONS[$mime];
    }

    private static function likeDescendants(string $directory): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $directory).'/%';
    }
}
