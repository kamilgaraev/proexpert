<?php

namespace App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use App\Models\User;

/**
 * Медиа-файлы сайта холдинга
 */
class SiteAsset extends Model
{
    protected $table = 'site_assets';

    protected $fillable = [
        'holding_site_id',
        'filename',
        'storage_path',
        'public_url',
        'mime_type',
        'file_size',
        'metadata',
        'asset_type',
        'usage_context',
        'is_optimized',
        'optimized_variants',
        'uploaded_by_user_id',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'metadata' => 'array',
        'is_optimized' => 'boolean',
        'optimized_variants' => 'array',
    ];

    /**
     * Типы ассетов
     */
    const ASSET_TYPES = [
        'image' => 'Изображение',
        'document' => 'Документ',
        'video' => 'Видео',
        'icon' => 'Иконка',
        'logo' => 'Логотип',
    ];

    /**
     * Контексты использования
     */
    const USAGE_CONTEXTS = [
        'hero' => 'Главный баннер',
        'logo' => 'Логотип',
        'gallery' => 'Галерея',
        'about' => 'О компании',
        'team' => 'Команда',
        'projects' => 'Проекты',
        'favicon' => 'Фавикон',
        'general' => 'Общее использование',
    ];

    /**
     * Сайт-владелец
     */
    public function holdingSite(): BelongsTo
    {
        return $this->belongsTo(HoldingSite::class);
    }

    /**
     * Загрузивший пользователь
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * Получить оптимизированную версию изображения
     */
    public function getOptimizedUrl(string $size = 'medium'): string
    {
        if (!$this->is_optimized || !$this->optimized_variants) {
            return $this->public_url;
        }

        return $this->optimized_variants[$size] ?? $this->public_url;
    }

    /**
     * Проверить является ли файл изображением
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * Получить человекочитаемый размер файла
     */
    public function getHumanReadableSize(): string
    {
        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Удалить файл из хранилища
     */
    public function deleteFile(): bool
    {
        // Удаляем основной файл
        if (Storage::exists($this->storage_path)) {
            Storage::delete($this->storage_path);
        }

        // Удаляем оптимизированные версии
        if ($this->optimized_variants) {
            foreach ($this->optimized_variants as $variant) {
                if (is_string($variant) && Storage::exists($variant)) {
                    Storage::delete($variant);
                }
            }
        }

        return $this->delete();
    }
}
