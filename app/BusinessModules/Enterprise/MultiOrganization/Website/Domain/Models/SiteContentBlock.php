<?php

namespace App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\User;

/**
 * Блок контента сайта холдинга
 */
class SiteContentBlock extends Model
{
    protected $table = 'site_content_blocks';

    protected $fillable = [
        'holding_site_id',
        'block_type',
        'block_key',
        'title',
        'content',
        'settings',
        'sort_order',
        'is_active',
        'status',
        'published_at',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'content' => 'array',
        'settings' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'published_at' => 'datetime',
    ];

    /**
     * Типы блоков
     */
    const BLOCK_TYPES = [
        'hero' => 'Главный баннер',
        'about' => 'О компании',
        'services' => 'Услуги',
        'projects' => 'Проекты',
        'team' => 'Команда',
        'contacts' => 'Контакты',
        'testimonials' => 'Отзывы',
        'gallery' => 'Галерея',
        'news' => 'Новости',
        'custom' => 'Произвольный блок',
    ];

    /**
     * Сайт-владелец блока
     */
    public function holdingSite(): BelongsTo
    {
        return $this->belongsTo(HoldingSite::class);
    }

    /**
     * Связанные медиа-файлы
     */
    public function assets(): HasMany
    {
        return $this->hasMany(SiteAsset::class, 'holding_site_id', 'holding_site_id')
            ->where('usage_context', $this->block_type);
    }

    /**
     * Создатель блока
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Обновивший блок
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Получить данные блока для публичного отображения
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->block_type,
            'key' => $this->block_key,
            'title' => $this->title,
            'content' => $this->content,
            'settings' => $this->settings,
            'sort_order' => $this->sort_order,
            'assets' => $this->assets->map(fn($asset) => [
                'id' => $asset->id,
                'filename' => $asset->filename,
                'public_url' => $asset->public_url,
                'mime_type' => $asset->mime_type,
                'metadata' => $asset->metadata,
            ])->toArray(),
        ];
    }

    /**
     * Валидация контента блока по типу
     */
    public function validateContent(): array
    {
        $errors = [];
        
        switch ($this->block_type) {
            case 'hero':
                if (empty($this->content['title'])) {
                    $errors[] = 'Заголовок обязателен для главного баннера';
                }
                break;
                
            case 'about':
                if (empty($this->content['description'])) {
                    $errors[] = 'Описание обязательно для блока "О компании"';
                }
                break;
                
            case 'contacts':
                if (empty($this->content['phone']) && empty($this->content['email'])) {
                    $errors[] = 'Укажите хотя бы один способ связи';
                }
                break;
        }
        
        return $errors;
    }

    /**
     * Опубликовать блок
     */
    public function publish(User $user): bool
    {
        $validationErrors = $this->validateContent();
        if (!empty($validationErrors)) {
            throw new \Exception('Ошибки валидации: ' . implode(', ', $validationErrors));
        }

        $this->update([
            'status' => 'published',
            'published_at' => now(),
            'updated_by_user_id' => $user->id,
        ]);

        // Очищаем кэш сайта
        $this->holdingSite->clearCache();
        
        return true;
    }

    /**
     * Получить схему контента по типу блока
     */
    public static function getContentSchema(string $blockType): array
    {
        return match ($blockType) {
            'hero' => [
                'title' => ['type' => 'string', 'required' => true],
                'subtitle' => ['type' => 'string', 'required' => false],
                'description' => ['type' => 'text', 'required' => false],
                'button_text' => ['type' => 'string', 'required' => false],
                'button_url' => ['type' => 'url', 'required' => false],
                'background_image' => ['type' => 'image', 'required' => false],
            ],
            'about' => [
                'title' => ['type' => 'string', 'required' => false],
                'description' => ['type' => 'html', 'required' => true],
                'image' => ['type' => 'image', 'required' => false],
                'features' => ['type' => 'array', 'required' => false],
            ],
            'contacts' => [
                'title' => ['type' => 'string', 'required' => false],
                'phone' => ['type' => 'string', 'required' => false],
                'email' => ['type' => 'email', 'required' => false],
                'address' => ['type' => 'text', 'required' => false],
                'working_hours' => ['type' => 'string', 'required' => false],
                'map_coordinates' => ['type' => 'coordinates', 'required' => false],
            ],
            'projects' => [
                'title' => ['type' => 'string', 'required' => false],
                'description' => ['type' => 'text', 'required' => false],
                'show_count' => ['type' => 'number', 'required' => false, 'default' => 6],
                'projects' => ['type' => 'projects_list', 'required' => false],
            ],
            default => [],
        };
    }
}
