<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image; // Предполагаем использование Intervention Image

class FileService
{
    protected FilesystemFactory $filesystem;

    public function __construct(FilesystemFactory $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * Загружает файл, опционально создает миниатюры.
     *
     * @param UploadedFile $uploadedFile
     * @param string $disk
     * @param string $pathPrefix
     * @param string|null $fileName Имя файла без расширения. Если null, генерируется UUID.
     * @param array $thumbnailConfigs Конфигурация для создания миниатюр.
     *                               Пример: ['thumb' => ['width' => 150, 'height' => 150, 'method' => 'fit']]
     * @return array Массив с информацией о файле и миниатюрах.
     * @throws \Exception
     */
    public function upload(
        UploadedFile $uploadedFile,
        string $disk,
        string $pathPrefix,
        ?string $fileName = null,
        array $thumbnailConfigs = []
    ): array
    {
        if (!$uploadedFile->isValid()) {
            throw new \InvalidArgumentException('Некорректный загруженный файл.');
        }

        $originalName = $uploadedFile->getClientOriginalName();
        $extension = $uploadedFile->getClientOriginalExtension();
        $mimeType = $uploadedFile->getMimeType();
        $size = $uploadedFile->getSize();

        $baseName = $fileName ?: Str::uuid()->toString();
        $name = $baseName . '.' . $extension;
        $fullPath = rtrim($pathPrefix, '/') . '/' . $name;

        try {
            // Сохранение оригинального файла
            $this->filesystem->disk($disk)->put($fullPath, $uploadedFile->get());

            $fileData = [
                'name' => $name,
                'original_name' => $originalName,
                'path' => $fullPath,
                'mime_type' => $mimeType,
                'size' => $size,
                'disk' => $disk,
                'thumbnails' => [],
            ];

            // Создание и сохранение миниатюр
            if (!empty($thumbnailConfigs) && Str::startsWith($mimeType, 'image/') && class_exists(Image::class)) {
                foreach ($thumbnailConfigs as $suffix => $config) {
                    try {
                        $thumbName = $baseName . '_' . $suffix . '.' . $extension;
                        $thumbPath = rtrim($pathPrefix, '/') . '/' . $thumbName;

                        $image = Image::make($uploadedFile->getRealPath());

                        $width = $config['width'] ?? null;
                        $height = $config['height'] ?? null;
                        $method = $config['method'] ?? 'fit'; // fit, resize, crop, etc.

                        switch ($method) {
                            case 'fit':
                                $image->fit($width, $height, function ($constraint) {
                                    $constraint->upsize();
                                });
                                break;
                            case 'resize':
                                $image->resize($width, $height, function ($constraint) {
                                    $constraint->aspectRatio();
                                    $constraint->upsize();
                                });
                                break;
                            // Можно добавить другие методы Intervention Image (crop, etc.)
                            default:
                                $image->fit($width ?: 100, $height ?: 100); // fallback
                                break;
                        }
                        
                        $this->filesystem->disk($disk)->put($thumbPath, (string) $image->encode());

                        $fileData['thumbnails'][$suffix] = [
                            'name' => $thumbName,
                            'path' => $thumbPath,
                        ];
                    } catch (\Throwable $e) {
                        Log::error("Ошибка создания миниатюры {$suffix} для файла {$originalName}: " . $e->getMessage());
                        // Не прерываем процесс из-за ошибки создания миниатюры
                    }
                }
            }
            return $fileData;
        } catch (\Throwable $e) {
            Log::error("Ошибка загрузки файла {$originalName}: " . $e->getMessage());
            throw new \Exception("Не удалось загрузить файл {$originalName}.", 0, $e);
        }
    }

    /**
     * Удаляет файл и его миниатюры.
     *
     * @param string $disk
     * @param string $path Путь к основному файлу.
     * @param array|null $thumbnailsInfo Массив информации о миниатюрах для удаления.
     *                                   Пример: ['thumb' => ['path' => 'path/to/thumb.jpg']]
     * @return bool
     */
    public function delete(string $disk, string $path, ?array $thumbnailsInfo = []): bool
    {
        $allDeleted = true;
        $storage = $this->filesystem->disk($disk);

        try {
            // Удаление основного файла
            if ($storage->exists($path)) {
                if (!$storage->delete($path)) {
                    Log::warning("Не удалось удалить основной файл: {$disk} -> {$path}");
                    $allDeleted = false;
                }
            } else {
                Log::info("Основной файл не найден для удаления: {$disk} -> {$path}");
            }

            // Удаление миниатюр
            if (!empty($thumbnailsInfo)) {
                foreach ($thumbnailsInfo as $suffix => $thumbData) {
                    if (is_array($thumbData) && isset($thumbData['path'])) {
                        $thumbPath = $thumbData['path'];
                        if ($storage->exists($thumbPath)) {
                            if (!$storage->delete($thumbPath)) {
                                Log::warning("Не удалось удалить миниатюру {$suffix}: {$disk} -> {$thumbPath}");
                                $allDeleted = false;
                            }
                        } else {
                            Log::info("Миниатюра {$suffix} не найдена для удаления: {$disk} -> {$thumbPath}");
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error("Ошибка при удалении файла {$path} или его миниатюр на диске {$disk}: " . $e->getMessage());
            return false;
        }
        return $allDeleted;
    }
} 