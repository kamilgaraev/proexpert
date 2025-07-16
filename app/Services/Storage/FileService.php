<?php

namespace App\Services\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use App\Models\Organization;
use Illuminate\Support\Facades\Auth;

class FileService
{
    public function __construct(protected OrgBucketService $bucketService)
    {
    }

    /**
     * Получить диск S3 для указанной организации (или текущей пользователя).
     */
    public function disk(?Organization $organization = null): FilesystemAdapter|Filesystem
    {
        $org = $organization;
        if (!$org) {
            $org = Auth::user()?->currentOrganization;
        }

        if ($org && $org->s3_bucket) {
            return $this->bucketService->getDisk($org);
        }

        return Storage::disk('s3'); // fallback на общий бакет
    }

    /**
     * Загрузить файл и вернуть путь или false.
     */
    public function upload(
        UploadedFile $file,
        string $directory,
        ?string $existingPath = null,
        string $visibility = 'public',
        ?Organization $organization = null
    ): string|false {
        $disk = $this->disk($organization);

        // удаляем существующий
        if ($existingPath && $disk->exists($existingPath)) {
            $disk->delete($existingPath);
        }

        $filename = Str::uuid()->toString() . '.' . $file->getClientOriginalExtension();
        $fullPath = $directory . '/' . $filename;

        try {
            $disk->putFileAs($directory, $file, $filename, $visibility);
            return $fullPath;
        } catch (\Throwable $e) {
            // можно логировать здесь, но оставляем на вызывающую сторону
            return false;
        }
    }

    public function delete(?string $path, ?Organization $organization = null): bool
    {
        if (!$path) {
            return true;
        }
        $disk = $this->disk($organization);
        return $disk->exists($path) ? $disk->delete($path) : true;
    }

    public function url(?string $path, ?Organization $organization = null): ?string
    {
        if (!$path) return null;
        return $this->disk($organization)->url($path);
    }

    public function temporaryUrl(?string $path, int $minutes = 5, ?Organization $organization = null): ?string
    {
        if (!$path) return null;
        return $this->disk($organization)->temporaryUrl($path, now()->addMinutes($minutes));
    }
} 