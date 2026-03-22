<?php

declare(strict_types=1);

namespace App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;

class SiteContentBlock extends Model
{
    protected $table = 'site_content_blocks';

    protected $fillable = [
        'holding_site_id',
        'holding_site_page_id',
        'block_type',
        'block_key',
        'title',
        'content',
        'settings',
        'bindings',
        'locale_content',
        'style_config',
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
        'bindings' => 'array',
        'locale_content' => 'array',
        'style_config' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'published_at' => 'datetime',
    ];

    public const BLOCK_TYPES = [
        'hero' => 'Hero',
        'stats' => 'Stats',
        'about' => 'About',
        'services' => 'Services',
        'projects' => 'Projects',
        'team' => 'Team',
        'testimonials' => 'Testimonials',
        'gallery' => 'Gallery',
        'faq' => 'FAQ',
        'lead_form' => 'Lead form',
        'contacts' => 'Contacts',
        'custom_html' => 'Custom HTML',
        'news' => 'News',
        'custom' => 'Custom HTML',
    ];

    public function holdingSite(): BelongsTo
    {
        return $this->belongsTo(HoldingSite::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(HoldingSitePage::class, 'holding_site_page_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(SiteAsset::class, 'holding_site_id', 'holding_site_id')
            ->where('usage_context', $this->block_type);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'type' => self::normalizeBlockType($this->block_type),
            'key' => $this->block_key,
            'title' => $this->title,
            'content' => $this->content ?? [],
            'settings' => $this->settings ?? [],
            'style_config' => $this->style_config ?? [],
            'sort_order' => $this->sort_order,
            'assets' => $this->assets->map(fn ($asset) => [
                'id' => $asset->id,
                'filename' => $asset->filename,
                'public_url' => $asset->public_url,
                'mime_type' => $asset->mime_type,
                'metadata' => $asset->metadata ?? [],
            ])->values()->all(),
        ];
    }

    public function validateContent(): array
    {
        $errors = [];
        $schema = self::getContentSchema($this->block_type);
        $bindings = $this->bindings ?? [];

        foreach ($schema as $field => $rules) {
            if (!($rules['required'] ?? false)) {
                continue;
            }

            $hasContent = !self::isEmptyValue(Arr::get($this->content ?? [], $field));
            $hasBinding = is_array($bindings[$field] ?? null) && !empty($bindings[$field]['source']);

            if (!$hasContent && !$hasBinding) {
                $errors[] = sprintf('Field "%s" is required.', $field);
            }
        }

        return $errors;
    }

    public function publish(User $user): bool
    {
        $validationErrors = $this->validateContent();
        if (!empty($validationErrors)) {
            throw new \RuntimeException(implode(' ', $validationErrors));
        }

        $this->update([
            'status' => 'published',
            'published_at' => now(),
            'updated_by_user_id' => $user->id,
        ]);

        $this->holdingSite->clearCache();

        return true;
    }

    public static function normalizeBlockType(string $blockType): string
    {
        return match ($blockType) {
            'custom' => 'custom_html',
            'cta' => 'lead_form',
            default => $blockType,
        };
    }

    public static function getDefaultContent(string $blockType): array
    {
        return match (self::normalizeBlockType($blockType)) {
            'hero' => [
                'title' => '',
                'subtitle' => '',
                'description' => '',
                'button_text' => 'Leave a request',
                'button_url' => '#lead-form',
                'background_image' => '',
            ],
            'stats' => [
                'title' => 'Why clients choose us',
                'description' => '',
                'items' => [],
            ],
            'about' => [
                'title' => 'About the holding',
                'description' => '',
                'image' => '',
            ],
            'services' => [
                'title' => 'Services',
                'description' => '',
                'services' => [],
            ],
            'projects' => [
                'title' => 'Projects and cases',
                'description' => '',
                'projects' => [],
                'show_count' => 6,
            ],
            'team' => [
                'title' => 'Team',
                'description' => '',
                'members' => [],
            ],
            'testimonials' => [
                'title' => 'Testimonials',
                'description' => '',
                'items' => [],
            ],
            'gallery' => [
                'title' => 'Gallery',
                'description' => '',
                'images' => [],
            ],
            'faq' => [
                'title' => 'FAQ',
                'description' => '',
                'items' => [],
            ],
            'lead_form' => [
                'title' => 'Request a consultation',
                'description' => 'Leave your contact details and we will get back to you.',
                'submit_label' => 'Send request',
                'success_message' => 'Your request has been sent.',
            ],
            'contacts' => [
                'title' => 'Contacts',
                'description' => '',
                'phone' => '',
                'email' => '',
                'address' => '',
                'working_hours' => '',
            ],
            'custom_html' => [
                'html' => '',
            ],
            default => [],
        };
    }

    public static function getDefaultBindings(string $blockType): array
    {
        return match (self::normalizeBlockType($blockType)) {
            'hero' => [
                'title' => ['mode' => 'hybrid', 'source' => 'organization.name'],
                'subtitle' => ['mode' => 'hybrid', 'source' => 'holding.description'],
                'description' => ['mode' => 'hybrid', 'source' => 'organization.description'],
            ],
            'stats' => [
                'items' => ['mode' => 'auto', 'source' => 'metrics.stats_items'],
            ],
            'about' => [
                'description' => ['mode' => 'hybrid', 'source' => 'organization.description'],
            ],
            'services' => [
                'services' => ['mode' => 'auto', 'source' => 'services.items'],
            ],
            'projects' => [
                'projects' => ['mode' => 'auto', 'source' => 'projects.items'],
            ],
            'team' => [
                'members' => ['mode' => 'auto', 'source' => 'team.members'],
            ],
            'contacts' => [
                'phone' => ['mode' => 'hybrid', 'source' => 'contacts.phone'],
                'email' => ['mode' => 'hybrid', 'source' => 'contacts.email'],
                'address' => ['mode' => 'hybrid', 'source' => 'contacts.address'],
            ],
            default => [],
        };
    }

    public static function getDefaultSettings(string $blockType): array
    {
        return match (self::normalizeBlockType($blockType)) {
            'hero' => ['variant' => 'split', 'theme' => 'primary'],
            'stats' => ['variant' => 'cards'],
            'lead_form' => ['variant' => 'card', 'anchor_id' => 'lead-form'],
            default => ['variant' => 'default'],
        };
    }

    public static function getContentSchema(string $blockType): array
    {
        return match (self::normalizeBlockType($blockType)) {
            'hero' => [
                'title' => ['type' => 'string', 'required' => true],
                'subtitle' => ['type' => 'string', 'required' => false],
                'description' => ['type' => 'text', 'required' => false],
                'button_text' => ['type' => 'string', 'required' => false],
                'button_url' => ['type' => 'url', 'required' => false],
                'background_image' => ['type' => 'image', 'required' => false],
            ],
            'stats' => [
                'title' => ['type' => 'string', 'required' => false],
                'description' => ['type' => 'text', 'required' => false],
                'items' => ['type' => 'array', 'required' => false],
            ],
            'about' => [
                'title' => ['type' => 'string', 'required' => false],
                'description' => ['type' => 'html', 'required' => true],
                'image' => ['type' => 'image', 'required' => false],
            ],
            'services' => [
                'title' => ['type' => 'string', 'required' => false],
                'description' => ['type' => 'text', 'required' => false],
                'services' => ['type' => 'array', 'required' => false],
            ],
            'projects' => [
                'title' => ['type' => 'string', 'required' => false],
                'description' => ['type' => 'text', 'required' => false],
                'projects' => ['type' => 'array', 'required' => false],
                'show_count' => ['type' => 'number', 'required' => false],
            ],
            'team' => [
                'title' => ['type' => 'string', 'required' => false],
                'description' => ['type' => 'text', 'required' => false],
                'members' => ['type' => 'array', 'required' => false],
            ],
            'testimonials' => [
                'title' => ['type' => 'string', 'required' => false],
                'description' => ['type' => 'text', 'required' => false],
                'items' => ['type' => 'array', 'required' => false],
            ],
            'gallery' => [
                'title' => ['type' => 'string', 'required' => false],
                'description' => ['type' => 'text', 'required' => false],
                'images' => ['type' => 'array', 'required' => false],
            ],
            'faq' => [
                'title' => ['type' => 'string', 'required' => false],
                'description' => ['type' => 'text', 'required' => false],
                'items' => ['type' => 'array', 'required' => false],
            ],
            'lead_form' => [
                'title' => ['type' => 'string', 'required' => true],
                'description' => ['type' => 'text', 'required' => false],
                'submit_label' => ['type' => 'string', 'required' => false],
                'success_message' => ['type' => 'string', 'required' => false],
            ],
            'contacts' => [
                'title' => ['type' => 'string', 'required' => false],
                'description' => ['type' => 'text', 'required' => false],
                'phone' => ['type' => 'string', 'required' => false],
                'email' => ['type' => 'email', 'required' => false],
                'address' => ['type' => 'text', 'required' => false],
                'working_hours' => ['type' => 'string', 'required' => false],
            ],
            'custom_html' => [
                'html' => ['type' => 'html', 'required' => false],
            ],
            default => [],
        };
    }

    public static function isEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::isEmptyValue($item)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }
}
