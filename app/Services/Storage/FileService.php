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
    /**
     * Получить диск S3 (всегда используем общий бакет).
     */
    public function disk(?Organization $organization = null): FilesystemAdapter|Filesystem
    {
        // Используем единый общий S3 бакет для всех организаций
        return Storage::disk('s3');
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
        
        // Получаем организацию для формирования пути
        $org = $this->getOrganization($organization);

        // Для Яндекс S3 с организациями используем private доступ (временные URL)
        $useVisibility = $visibility;
        if ($organization) {
            $useVisibility = null; // private по умолчанию
        }

        // Пытаемся удалить старый файл если он существует
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
        
        // Формируем путь с префиксом организации: org-{id}/directory/filename
        $orgPrefix = $org ? "org-{$org->id}" : 'shared';
        $fullPath = $orgPrefix . '/' . $directory . '/' . $filename;

        try {
            Log::info('[FileService] upload(): starting upload', [
                'org_prefix' => $orgPrefix,
                'directory' => $directory, 
                'filename' => $filename,
                'full_path' => $fullPath,
                'org_id' => $org?->id,
                'visibility' => $visibility,
            ]);

            // Используем полный путь для загрузки  
            $fileContent = file_get_contents($file->getRealPath());
            
            Log::info('[FileService] File content prepared', [
                'file_size' => strlen($fileContent),
                'file_path' => $file->getRealPath(),
            ]);
            
            if ($useVisibility) {
                $result = $disk->put($fullPath, $fileContent, $useVisibility);
            } else {
                $result = $disk->put($fullPath, $fileContent);
            }
            
            if ($result) {
                Log::info('[FileService] upload(): file uploaded successfully', [
                    'path' => $fullPath,
                    'org_id' => $org?->id,
                    'visibility' => $useVisibility,
                ]);
                return $fullPath;
            }
            
            Log::error('[FileService] upload(): put returned false', [
                'path' => $fullPath,
            ]);
            return false;
        } catch (\Throwable $e) {
            Log::error('[FileService] upload(): failed', [
                'path' => $fullPath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
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

    /**
     * Получить организацию для определения префикса пути.
     */
    private function getOrganization(?Organization $organization = null): ?Organization
    {
        $org = $organization;
        if (!$org) {
            $org = Auth::user()?->currentOrganization;
        }
        // Фолбек на статический контекст, который уже выставлен middleware
        if (!$org) {
            $org = \App\Services\Organization\OrganizationContext::getOrganization();
        }
        return $org;
    }
} 