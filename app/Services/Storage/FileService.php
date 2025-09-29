<?php

namespace App\Services\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use App\Models\Organization;
use App\Services\Logging\LoggingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class FileService
{
    protected LoggingService $logging;

    public function __construct(LoggingService $logging)
    {
        $this->logging = $logging;
    }

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

        $startTime = microtime(true);
        $fileSizeMb = round($file->getSize() / 1024 / 1024, 2);
        
        // TECHNICAL: Начало загрузки файла в S3
        $this->logging->technical('s3.upload.started', [
            'filename' => $file->getClientOriginalName(),
            'original_name' => $file->getClientOriginalName(),
            'generated_filename' => $filename,
            'file_size_mb' => $fileSizeMb,
            'mime_type' => $file->getClientMimeType(),
            'organization_id' => $org?->id,
            'directory' => $directory,
            'full_s3_path' => $fullPath,
            'visibility' => $visibility,
            'org_prefix' => $orgPrefix
        ]);

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
            
            // Логируем конфигурацию диска
            $diskConfig = $disk->getConfig();
            Log::info('[FileService] Disk config', [
                'driver' => $diskConfig['driver'] ?? 'unknown',
                'bucket' => $diskConfig['bucket'] ?? 'unknown',
                'endpoint' => $diskConfig['endpoint'] ?? 'unknown',
                'region' => $diskConfig['region'] ?? 'unknown',
            ]);
            
            try {
                if ($useVisibility) {
                    $result = $disk->put($fullPath, $fileContent, $useVisibility);
                } else {
                    $result = $disk->put($fullPath, $fileContent);
                }
            } catch (\Exception $e) {
                $durationMs = round((microtime(true) - $startTime) * 1000, 2);
                
                // TECHNICAL: Критическая ошибка загрузки в S3
                $this->logging->technical('s3.upload.failed', [
                    'filename' => $file->getClientOriginalName(),
                    'file_size_mb' => $fileSizeMb,
                    's3_path' => $fullPath,
                    'organization_id' => $org?->id,
                    'duration_ms' => $durationMs,
                    'exception_class' => get_class($e),
                    'exception_message' => $e->getMessage(),
                    'aws_error_code' => $e instanceof \Aws\Exception\AwsException ? $e->getAwsErrorCode() : null
                ], 'error');
                
                Log::error('[FileService] S3 put() exception', [
                    'path' => $fullPath,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return false;
            }
            
            if ($result) {
                $durationMs = round((microtime(true) - $startTime) * 1000, 2);
                
                // TECHNICAL: Успешная загрузка файла в S3
                $this->logging->technical('s3.upload.success', [
                    'filename' => $file->getClientOriginalName(),
                    'generated_filename' => $filename,
                    'file_size_mb' => $fileSizeMb,
                    's3_path' => $fullPath,
                    'organization_id' => $org?->id,
                    'duration_ms' => $durationMs,
                    'upload_speed_mbps' => $durationMs > 0 ? round(($fileSizeMb * 8 * 1000) / $durationMs, 2) : null,
                    'visibility' => $visibility,
                    'directory' => $directory
                ]);

                // BUSINESS: Загрузка файла - важная бизнес-метрика использования хранилища
                $this->logging->business('file.uploaded', [
                    'filename' => $file->getClientOriginalName(),
                    'file_size_mb' => $fileSizeMb,
                    'organization_id' => $org?->id,
                    'directory' => $directory,
                    'user_id' => Auth::id()
                ]);
                
                Log::info('[FileService] upload(): file uploaded successfully', [
                    'path' => $fullPath,
                    'org_id' => $org?->id,
                    'visibility' => $useVisibility,
                ]);
                return $fullPath;
            }
            
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);
            
            // TECHNICAL: S3 put вернул false
            $this->logging->technical('s3.upload.put_failed', [
                'filename' => $file->getClientOriginalName(),
                'file_size_mb' => $fileSizeMb,
                's3_path' => $fullPath,
                'organization_id' => $org?->id,
                'duration_ms' => $durationMs,
                'result' => false
            ], 'error');
            
            Log::error('[FileService] upload(): put returned false', [
                'path' => $fullPath,
            ]);
            return false;
        } catch (\Throwable $e) {
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);
            
            // TECHNICAL: Общая ошибка загрузки
            $this->logging->technical('s3.upload.exception', [
                'filename' => $file->getClientOriginalName(),
                'file_size_mb' => $fileSizeMb,
                's3_path' => $fullPath,
                'organization_id' => $org?->id,
                'duration_ms' => $durationMs,
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
                'file_path' => $e->getFile(),
                'line' => $e->getLine()
            ], 'error');
            
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
        
        $startTime = microtime(true);
        
        // TECHNICAL: Начало удаления файла из S3
        $this->logging->technical('s3.delete.started', [
            's3_path' => $path,
            'organization_id' => $organization?->id
        ]);
        
        try {
            $disk = $this->disk($organization);
            $result = $disk->delete($path);
            
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);
            
            if ($result) {
                // TECHNICAL: Успешное удаление файла
                $this->logging->technical('s3.delete.success', [
                    's3_path' => $path,
                    'organization_id' => $organization?->id,
                    'duration_ms' => $durationMs
                ]);
            } else {
                // TECHNICAL: Удаление вернуло false
                $this->logging->technical('s3.delete.failed', [
                    's3_path' => $path,
                    'organization_id' => $organization?->id,
                    'duration_ms' => $durationMs,
                    'result' => false
                ], 'warning');
            }
            
            return $result;
        } catch (\Throwable $e) {
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);
            
            // TECHNICAL: Ошибка при удалении файла
            $this->logging->technical('s3.delete.exception', [
                's3_path' => $path,
                'organization_id' => $organization?->id,
                'duration_ms' => $durationMs,
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage()
            ], 'error');
            
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