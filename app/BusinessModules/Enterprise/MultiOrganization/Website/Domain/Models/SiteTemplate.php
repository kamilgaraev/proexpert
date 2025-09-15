<?php

namespace App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

/**
 * Шаблоны дизайна для сайтов холдингов
 */
class SiteTemplate extends Model
{
    protected $table = 'site_templates';

    protected $fillable = [
        'template_key',
        'name',
        'description',
        'preview_image',
        'default_blocks',
        'theme_options',
        'layout_config',
        'is_active',
        'is_premium',
        'version',
        'created_by_user_id',
    ];

    protected $casts = [
        'default_blocks' => 'array',
        'theme_options' => 'array',
        'layout_config' => 'array',
        'is_active' => 'boolean',
        'is_premium' => 'boolean',
    ];

    /**
     * Создатель шаблона
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Получить структуру блоков по умолчанию
     */
    public function getDefaultBlocksStructure(): array
    {
        return $this->default_blocks ?? $this->getStandardBlocksStructure();
    }

    /**
     * Стандартная структура блоков
     */
    private function getStandardBlocksStructure(): array
    {
        return [
            [
                'block_type' => 'hero',
                'block_key' => 'main_hero',
                'title' => 'Главный баннер',
                'sort_order' => 1,
                'content' => [
                    'title' => 'Добро пожаловать в нашу компанию',
                    'subtitle' => 'Мы предоставляем качественные услуги',
                    'button_text' => 'Связаться с нами',
                    'button_url' => '#contacts',
                ],
            ],
            [
                'block_type' => 'about',
                'block_key' => 'company_about',
                'title' => 'О компании',
                'sort_order' => 2,
                'content' => [
                    'title' => 'О нашей компании',
                    'description' => '<p>Здесь расположен текст о компании.</p>',
                ],
            ],
            [
                'block_type' => 'services',
                'block_key' => 'our_services',
                'title' => 'Наши услуги',
                'sort_order' => 3,
                'content' => [
                    'title' => 'Что мы предлагаем',
                    'services' => [],
                ],
            ],
            [
                'block_type' => 'projects',
                'block_key' => 'recent_projects',
                'title' => 'Наши проекты',
                'sort_order' => 4,
                'content' => [
                    'title' => 'Реализованные проекты',
                    'show_count' => 6,
                ],
            ],
            [
                'block_type' => 'contacts',
                'block_key' => 'contact_info',
                'title' => 'Контакты',
                'sort_order' => 5,
                'content' => [
                    'title' => 'Свяжитесь с нами',
                    'phone' => '',
                    'email' => '',
                    'address' => '',
                ],
            ],
        ];
    }

    /**
     * Получить доступные опции темы
     */
    public function getAvailableThemeOptions(): array
    {
        return $this->theme_options ?? [
            'colors' => [
                'primary' => '#2563eb',
                'secondary' => '#64748b',
                'accent' => '#f59e0b',
                'background' => '#ffffff',
                'text' => '#1f2937',
            ],
            'typography' => [
                'heading_font' => 'Inter',
                'body_font' => 'Inter',
                'font_size_base' => '16px',
            ],
            'layout' => [
                'container_width' => '1200px',
                'header_style' => 'fixed',
                'footer_style' => 'simple',
            ],
        ];
    }

    /**
     * Создать сайт на основе шаблона
     */
    public function createSiteFromTemplate(HoldingSite $site, User $user): bool
    {
        $blocks = $this->getDefaultBlocksStructure();
        
        foreach ($blocks as $blockData) {
            SiteContentBlock::create([
                'holding_site_id' => $site->id,
                'block_type' => $blockData['block_type'],
                'block_key' => $blockData['block_key'],
                'title' => $blockData['title'],
                'content' => $blockData['content'],
                'sort_order' => $blockData['sort_order'],
                'is_active' => true,
                'status' => 'draft',
                'created_by_user_id' => $user->id,
            ]);
        }

        return true;
    }
}
