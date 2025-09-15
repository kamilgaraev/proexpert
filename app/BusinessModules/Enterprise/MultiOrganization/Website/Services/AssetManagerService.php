<?php

namespace App\BusinessModules\Enterprise\MultiOrganization\Website\Services;

use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\SiteAsset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Сервис управления файлами и медиа для сайтов
 */
class AssetManagerService
{
    public function __construct()
    {
        // Используем встроенные PHP функции для работы с изображениями
    }

    /**
     * Загрузить файл
     */
    public function uploadAsset(HoldingSite $site, UploadedFile $file, string $usageContext, User $uploader): SiteAsset
    {
        // Генерируем путь для сохранения
        $storagePath = $this->generateStoragePath($site, $file);
        
        // Сохраняем файл
        $path = $file->storeAs('holding-sites', $storagePath, 'public');
        
        // Получаем публичный URL
        $publicUrl = Storage::url($path);

        // Создаем запись в БД
        $asset = SiteAsset::create([
            'holding_site_id' => $site->id,
            'filename' => $file->getClientOriginalName(),
            'storage_path' => $path,
            'public_url' => $publicUrl,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'metadata' => $this->extractMetadata($file),
            'asset_type' => $this->determineAssetType($file),
            'usage_context' => $usageContext,
            'is_optimized' => false,
            'uploaded_by_user_id' => $uploader->id,
        ]);

        // Оптимизируем изображения
        if ($asset->isImage()) {
            $this->optimizeImage($asset);
        }

        return $asset;
    }

    /**
     * Получить все ассеты сайта
     */
    public function getSiteAssets(HoldingSite $site, ?string $assetType = null, ?string $usageContext = null): array
    {
        $query = $site->assets()->orderBy('created_at', 'desc');

        if ($assetType) {
            $query->where('asset_type', $assetType);
        }

        if ($usageContext) {
            $query->where('usage_context', $usageContext);
        }

        return $query->get()->map(function ($asset) {
            return [
                'id' => $asset->id,
                'filename' => $asset->filename,
                'public_url' => $asset->public_url,
                'optimized_url' => $asset->getOptimizedUrl(),
                'mime_type' => $asset->mime_type,
                'file_size' => $asset->file_size,
                'human_size' => $asset->getHumanReadableSize(),
                'asset_type' => $asset->asset_type,
                'usage_context' => $asset->usage_context,
                'metadata' => $asset->metadata,
                'is_optimized' => $asset->is_optimized,
                'uploaded_at' => $asset->created_at,
                'uploader' => $asset->uploader->name ?? 'Неизвестно',
            ];
        })->toArray();
    }

    /**
     * Обновить метаданные ассета
     */
    public function updateAssetMetadata(SiteAsset $asset, array $metadata): bool
    {
        $currentMetadata = $asset->metadata ?? [];
        $newMetadata = array_merge($currentMetadata, $metadata);

        return $asset->update(['metadata' => $newMetadata]);
    }

    /**
     * Удалить ассет
     */
    public function deleteAsset(SiteAsset $asset): bool
    {
        return $asset->deleteFile();
    }

    /**
     * Оптимизировать изображение с помощью встроенных PHP функций
     */
    private function optimizeImage(SiteAsset $asset): void
    {
        if (!$asset->isImage()) {
            return;
        }

        try {
            $originalPath = Storage::path($asset->storage_path);
            
            // Получаем информацию об изображении
            $imageInfo = getimagesize($originalPath);
            if (!$imageInfo) {
                throw new \Exception('Unable to read image information');
            }
            
            [$originalWidth, $originalHeight, $imageType] = $imageInfo;

            $variants = [];
            $basePath = pathinfo($asset->storage_path, PATHINFO_DIRNAME);
            $filename = pathinfo($asset->storage_path, PATHINFO_FILENAME);
            $extension = pathinfo($asset->storage_path, PATHINFO_EXTENSION);

            // Создаем разные размеры
            $sizes = [
                'thumbnail' => [150, 150],
                'small' => [300, 300],
                'medium' => [600, 600],
                'large' => [1200, 1200],
            ];

            foreach ($sizes as $sizeName => [$maxWidth, $maxHeight]) {
                // Вычисляем новые размеры с сохранением пропорций
                $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
                
                // Не увеличиваем изображение
                if ($ratio > 1) {
                    $ratio = 1;
                }
                
                $newWidth = (int)($originalWidth * $ratio);
                $newHeight = (int)($originalHeight * $ratio);

                // Создаем новое изображение
                $newImage = imagecreatetruecolor($newWidth, $newHeight);
                
                // Сохраняем прозрачность для PNG
                if ($imageType === IMAGETYPE_PNG) {
                    imagealphablending($newImage, false);
                    imagesavealpha($newImage, true);
                    $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
                    imagefill($newImage, 0, 0, $transparent);
                }

                // Загружаем оригинальное изображение
                $sourceImage = null;
                switch ($imageType) {
                    case IMAGETYPE_JPEG:
                        $sourceImage = imagecreatefromjpeg($originalPath);
                        break;
                    case IMAGETYPE_PNG:
                        $sourceImage = imagecreatefrompng($originalPath);
                        break;
                    case IMAGETYPE_GIF:
                        $sourceImage = imagecreatefromgif($originalPath);
                        break;
                    default:
                        continue 2; // Пропускаем неподдерживаемые форматы
                }

                if (!$sourceImage) {
                    continue;
                }

                // Изменяем размер
                imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

                // Сохраняем оптимизированную версию
                $variantPath = "{$basePath}/{$filename}_{$sizeName}.{$extension}";
                $fullVariantPath = Storage::path($variantPath);

                // Создаем директорию если не существует
                $variantDir = dirname($fullVariantPath);
                if (!is_dir($variantDir)) {
                    mkdir($variantDir, 0755, true);
                }

                $saved = false;
                switch ($imageType) {
                    case IMAGETYPE_JPEG:
                        $saved = imagejpeg($newImage, $fullVariantPath, 85);
                        break;
                    case IMAGETYPE_PNG:
                        $saved = imagepng($newImage, $fullVariantPath, 8);
                        break;
                    case IMAGETYPE_GIF:
                        $saved = imagegif($newImage, $fullVariantPath);
                        break;
                }

                if ($saved) {
                    $variants[$sizeName] = Storage::url($variantPath);
                }

                // Освобождаем память
                imagedestroy($newImage);
                imagedestroy($sourceImage);
            }

            // Обновляем запись с информацией об оптимизированных версиях
            $asset->update([
                'is_optimized' => true,
                'optimized_variants' => $variants,
                'metadata' => array_merge($asset->metadata ?? [], [
                    'original_width' => $originalWidth,
                    'original_height' => $originalHeight,
                    'optimized_at' => now()->toISOString(),
                ]),
            ]);

        } catch (\Exception $e) {
            // Логируем ошибку, но не останавливаем процесс
            Log::warning('Failed to optimize image', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Генерировать путь для сохранения
     */
    private function generateStoragePath(HoldingSite $site, UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $filename = Str::random(40) . '.' . $extension;
        
        return "{$site->id}/" . date('Y/m/') . $filename;
    }

    /**
     * Извлечь метаданные файла
     */
    private function extractMetadata(UploadedFile $file): array
    {
        $metadata = [
            'original_name' => $file->getClientOriginalName(),
            'uploaded_at' => now()->toISOString(),
        ];

        // Для изображений извлекаем дополнительную информацию
        if (str_starts_with($file->getMimeType(), 'image/')) {
            try {
                $imageSize = getimagesize($file->getRealPath());
                if ($imageSize) {
                    $metadata['width'] = $imageSize[0];
                    $metadata['height'] = $imageSize[1];
                    $metadata['aspect_ratio'] = round($imageSize[0] / $imageSize[1], 2);
                }
            } catch (\Exception $e) {
                // Игнорируем ошибки извлечения метаданных
            }
        }

        return $metadata;
    }

    /**
     * Определить тип ассета
     */
    private function determineAssetType(UploadedFile $file): string
    {
        $mimeType = $file->getMimeType();

        if (str_starts_with($mimeType, 'image/')) {
            // Определяем подтип изображения
            if (in_array($mimeType, ['image/x-icon', 'image/vnd.microsoft.icon'])) {
                return 'icon';
            }
            
            $filename = strtolower($file->getClientOriginalName());
            if (str_contains($filename, 'logo')) {
                return 'logo';
            }
            
            return 'image';
        }

        if (str_starts_with($mimeType, 'video/')) {
            return 'video';
        }

        return 'document';
    }

    /**
     * Получить допустимые типы файлов для загрузки
     */
    public static function getAllowedMimeTypes(): array
    {
        return [
            // Изображения
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
            'image/x-icon',
            'image/vnd.microsoft.icon',
            
            // Документы
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            
            // Видео (для будущего использования)
            'video/mp4',
            'video/webm',
        ];
    }

    /**
     * Получить максимальный размер файла (в байтах)
     */
    public static function getMaxFileSize(): int
    {
        return 10 * 1024 * 1024; // 10 MB
    }
}
