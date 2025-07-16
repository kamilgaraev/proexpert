<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ImageUploadService
{
    /** @deprecated Используйте FileService */
    public function __construct(protected \App\Services\Storage\FileService $fileService)
    {
    }

    public function upload(UploadedFile $file, string $directory, ?string $existingPath = null, string $visibility = 'public'): string|false
    {
        return $this->fileService->upload($file, $directory, $existingPath, $visibility);
    }

    public function delete(?string $path): bool
    {
        return $this->fileService->delete($path);
    }

    public function getUrl(?string $path): ?string
    {
        return $this->fileService->url($path);
    }

    public function getTemporaryUrl(?string $path, int $minutes = 5): ?string
    {
        return $this->fileService->temporaryUrl($path, $minutes);
    }
} 