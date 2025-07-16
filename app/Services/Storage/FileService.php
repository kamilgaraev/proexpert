<?php

namespace App\Services\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use App\Models\Organization;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

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
            $disk = $this->bucketService->getDisk($org);
            Log::debug('[FileService] disk(): org-specific disk resolved', [
                'org_id' => $org->id,
                'bucket' => $org->s3_bucket,
            ]);
            return $disk;
        }

        Log::debug('[FileService] disk(): fallback to shared disk s3');
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

        // Пытаемся удалить старый файл без предварительной проверки наличия
        if ($existingPath) {
            try {
                $disk->delete($existingPath);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to delete previous file', [
                    'path' => $existingPath,
                    'err'  => $e->getMessage(),
                ]);
            }
        }

        $filename = Str::uuid()->toString() . '.' . $file->getClientOriginalExtension();
        $fullPath = $directory . '/' . $filename;

        try {
            $disk->putFileAs($directory, $file, $filename, $visibility);
            Log::info('[FileService] upload(): file uploaded', [
                'path' => $fullPath,
                'org_id' => $organization?->id,
                'visibility' => $visibility,
            ]);
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
        try {
            $disk = $this->disk($organization);
            return $disk->delete($path);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Delete file failed', [
                'path' => $path,
                'err'  => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function url(?string $path, ?Organization $organization = null): ?string
    {
        if (!$path) return null;
        $disk = $this->disk($organization);
        try {
            $url = $disk->url($path);
        } catch (\Throwable $e) {
            Log::warning('[FileService] url() failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
        Log::debug('[FileService] url(): generated', [
            'path' => $path,
            'url' => $url,
        ]);
        return $url;
    }

    public function temporaryUrl(?string $path, int $minutes = 5, ?Organization $organization = null): ?string
    {
        if (!$path) return null;
        $disk = $this->disk($organization);
        try {
            $url = $disk->temporaryUrl($path, now()->addMinutes($minutes));
        } catch (\Throwable $e) {
            Log::warning('[FileService] temporaryUrl() failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
        Log::debug('[FileService] temporaryUrl(): generated', [
            'path' => $path,
            'url' => $url,
            'expires_in_minutes' => $minutes,
        ]);
        return $url;
    }
} 