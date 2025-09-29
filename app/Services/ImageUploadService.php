<?php

namespace App\Services;

use App\Services\Logging\LoggingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ImageUploadService
{
    protected LoggingService $logging;
    
    /** @deprecated Используйте FileService */
    public function __construct(protected \App\Services\Storage\FileService $fileService, LoggingService $logging)
    {
        $this->logging = $logging;
    }

    public function upload(UploadedFile $file, string $directory, ?string $existingPath = null, string $visibility = 'public'): string|false
    {
        $this->logging->technical('image_upload.deprecated_service.used', [
            'method' => 'upload',
            'directory' => $directory,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'file_mime' => $file->getMimeType(),
            'has_existing_path' => $existingPath !== null,
            'visibility' => $visibility,
            'deprecation_warning' => 'Please use FileService directly instead'
        ], 'warning');
        
        return $this->fileService->upload($file, $directory, $existingPath, $visibility);
    }

    public function delete(?string $path): bool
    {
        $this->logging->technical('image_upload.deprecated_service.used', [
            'method' => 'delete',
            'path' => $path,
            'deprecation_warning' => 'Please use FileService directly instead'
        ], 'warning');
        
        return $this->fileService->delete($path);
    }

    public function getUrl(?string $path): ?string
    {
        $this->logging->technical('image_upload.deprecated_service.used', [
            'method' => 'getUrl',
            'path' => $path,
            'deprecation_warning' => 'Please use FileService directly instead'
        ], 'warning');
        
        return $this->fileService->url($path);
    }

    public function getTemporaryUrl(?string $path, int $minutes = 5): ?string
    {
        $this->logging->technical('image_upload.deprecated_service.used', [
            'method' => 'getTemporaryUrl',
            'path' => $path,
            'minutes' => $minutes,
            'deprecation_warning' => 'Please use FileService directly instead'
        ], 'warning');
        
        return $this->fileService->temporaryUrl($path, $minutes);
    }
} 