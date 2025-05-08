<?php

namespace App\Traits;

use App\Services\ImageUploadService;
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
     * @return string|null
     */
    public function getImageUrl(string $attributeName, ?string $defaultUrl = null, bool $temporary = false, int $temporaryUrlMinutes = 5): ?string
    {
        $imagePath = $this->{$attributeName};

        if (!$imagePath) {
            return $defaultUrl;
        }

        /** @var ImageUploadService $service */
        $service = App::make(ImageUploadService::class);

        if ($temporary) {
            return $service->getTemporaryUrl($imagePath, $temporaryUrlMinutes);
        }

        return $service->getUrl($imagePath);
    }

    /**
     * Загружает изображение и устанавливает путь в атрибут модели (БЕЗ СОХРАНЕНИЯ).
     *
     * @param UploadedFile $file Файл для загрузки.
     * @param string $attributeName Имя атрибута модели для сохранения пути (например, 'avatar_path').
     * @param string $directory Директория в S3 (например, 'avatars').
     * @param string $visibility Видимость файла ('public' или 'private').
     * @return bool True в случае успеха установки пути, false в случае ошибки загрузки.
     */
    public function uploadImage(UploadedFile $file, string $attributeName, string $directory, string $visibility = 'public'): bool
    {
        /** @var ImageUploadService $service */
        $service = App::make(ImageUploadService::class);

        $existingPath = $this->{$attributeName};
        $newPath = $service->upload($file, $directory, $existingPath, $visibility);

        if ($newPath) {
            $this->{$attributeName} = $newPath;
            return true; // Путь установлен, но модель НЕ сохранена
        }

        return false;
    }

    /**
     * Удаляет изображение с диска и очищает путь в атрибуте модели (БЕЗ СОХРАНЕНИЯ).
     *
     * @param string $attributeName Имя атрибута модели, хранящего путь (например, 'avatar_path').
     * @return bool True в случае успеха удаления файла с диска (или если его не было).
     */
    public function deleteImage(string $attributeName): bool
    {
        /** @var ImageUploadService $service */
        $service = App::make(ImageUploadService::class);

        $pathToDelete = $this->{$attributeName};
        
        if ($service->delete($pathToDelete)) {
            $this->{$attributeName} = null; // Очищаем путь в модели
            return true; // Удаление успешно (или файла не было), модель НЕ сохранена
        }

        // Если удаление на диске не удалось, не очищаем путь и возвращаем false
        return false; 
    }
} 