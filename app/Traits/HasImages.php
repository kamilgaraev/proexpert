<?php

namespace App\Traits;

use App\Services\Storage\FileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;

trait HasImages
{
    /**
     * Получить URL изображения для указанного атрибута (поля).
     *
     * @param string $attributeName Имя атрибута модели, хранящего путь к файлу (например, 'avatar_path').
     * @param string|null $defaultUrl URL изображения по умолчанию, если основное отсутствует.
     * @param bool $temporary Если true, генерировать временный URL (для приватных файлов).
     * @param int $temporaryUrlMinutes Время жизни временного URL в минутах.
     * @param \App\Models\Organization|null $organization Организация для получения правильного бакета.
     * @return string|null
     */
    public function getImageUrl(string $attributeName, ?string $defaultUrl = null, bool $temporary = false, int $temporaryUrlMinutes = 5, ?\App\Models\Organization $organization = null): ?string
    {
        $imagePath = $this->{$attributeName};

        if (!$imagePath) {
            return $defaultUrl;
        }

        // Если организация не передана, пытаемся определить из модели
        if (!$organization) {
            $organization = $this->getOrganizationForImages();
        }

        /** @var FileService $service */
        $service = App::make(FileService::class);

        try {
            if ($temporary) {
                return $service->temporaryUrl($imagePath, $temporaryUrlMinutes, $organization);
            }

            return $service->url($imagePath, $organization);
        } catch (\Throwable $e) {
            // При любых ошибках S3 (квота бакетов, сеть и т.д.) возвращаем дефолтный URL
            \Illuminate\Support\Facades\Log::error('[HasImages] getImageUrl failed, using default', [
                'path' => $imagePath,
                'error' => $e->getMessage(),
                'model' => get_class($this),
            ]);
            return $defaultUrl;
        }
    }

    /**
     * Загружает изображение и устанавливает путь в атрибут модели (БЕЗ СОХРАНЕНИЯ).
     *
     * @param UploadedFile $file Файл для загрузки.
     * @param string $attributeName Имя атрибута модели для сохранения пути (например, 'avatar_path').
     * @param string $directory Директория в S3 (например, 'avatars').
     * @param string $visibility Видимость файла ('public' или 'private').
     * @param \App\Models\Organization|null $organization Организация для получения правильного бакета.
     * @return bool True в случае успеха установки пути, false в случае ошибки загрузки.
     */
    public function uploadImage(UploadedFile $file, string $attributeName, string $directory, string $visibility = 'public', ?\App\Models\Organization $organization = null): bool
    {
        // Если организация не передана, пытаемся определить из модели
        if (!$organization) {
            $organization = $this->getOrganizationForImages();
        }

        /** @var FileService $service */
        $service = App::make(FileService::class);

        try {
            $existingPath = $this->{$attributeName};
            
            \Illuminate\Support\Facades\Log::info('[HasImages] uploadImage starting', [
                'directory' => $directory,
                'existing_path' => $existingPath,
                'visibility' => $visibility,
                'model' => get_class($this),
                'organization_id' => $organization?->id,
            ]);
            
            $newPath = $service->upload($file, $directory, $existingPath, $visibility, $organization);

            if ($newPath) {
                $this->{$attributeName} = $newPath;
                \Illuminate\Support\Facades\Log::info('[HasImages] uploadImage success', [
                    'new_path' => $newPath,
                    'model' => get_class($this),
                ]);
                return true; // Путь установлен, но модель НЕ сохранена
            }

            \Illuminate\Support\Facades\Log::error('[HasImages] uploadImage failed - no path returned', [
                'directory' => $directory,
                'model' => get_class($this),
            ]);
            return false;
        } catch (\Throwable $e) {
            // При ошибках S3 логируем, но не ломаем процесс загрузки
            \Illuminate\Support\Facades\Log::error('[HasImages] uploadImage exception', [
                'directory' => $directory,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'model' => get_class($this),
            ]);
            return false;
        }
    }

    /**
     * Удаляет изображение с диска и очищает путь в атрибуте модели (БЕЗ СОХРАНЕНИЯ).
     *
     * @param string $attributeName Имя атрибута модели, хранящего путь (например, 'avatar_path').
     * @param \App\Models\Organization|null $organization Организация для получения правильного бакета.
     * @return bool True в случае успеха удаления файла с диска (или если его не было).
     */
    public function deleteImage(string $attributeName, ?\App\Models\Organization $organization = null): bool
    {
        // Если организация не передана, пытаемся определить из модели
        if (!$organization) {
            $organization = $this->getOrganizationForImages();
        }

        /** @var FileService $service */
        $service = App::make(FileService::class);

        $pathToDelete = $this->{$attributeName};
        
        try {
            if ($service->delete($pathToDelete, $organization)) {
                $this->{$attributeName} = null; // Очищаем путь в модели
                return true; // Удаление успешно (или файла не было), модель НЕ сохранена
            }

            // Если удаление на диске не удалось, не очищаем путь и возвращаем false
            return false;
        } catch (\Throwable $e) {
            // При ошибках S3 логируем, но считаем что удаление "успешно"
            \Illuminate\Support\Facades\Log::error('[HasImages] deleteImage failed', [
                'path' => $pathToDelete,
                'error' => $e->getMessage(),
                'model' => get_class($this),
            ]);
            // Очищаем путь в модели даже если удаление на диске не удалось
            $this->{$attributeName} = null;
            return true;
        } 
    }

    /**
     * Получить организацию для работы с изображениями.
     * Метод должен быть переопределен в моделях, которые используют HasImages.
     *
     * @return \App\Models\Organization|null
     */
    protected function getOrganizationForImages(): ?\App\Models\Organization
    {
        // По умолчанию возвращаем null, что приведет к использованию FileService::disk() без параметров
        return null;
    }
} 