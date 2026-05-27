<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlogArticleResource\Schemas;

use App\Models\Blog\BlogMediaAsset;
use Filament\Forms;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class BlogEditorBlocks
{
    public static function blocks(): array
    {
        return [
            Builder\Block::make('paragraph')
                ->label(fn (?array $state): string => self::resolveTextBlockLabel(trans_message('blog_cms.editor_block_paragraph'), $state))
                ->icon('heroicon-o-bars-3-bottom-left')
                ->preview('filament.blog.article-editor.blocks.paragraph')
                ->schema([
                    Forms\Components\Textarea::make('content')->label(trans_message('blog_cms.editor_field_text'))->rows(5)->required(),
                ]),
            Builder\Block::make('heading')
                ->label(fn (?array $state): string => self::resolveTextBlockLabel(trans_message('blog_cms.editor_block_heading'), $state))
                ->icon('heroicon-o-hashtag')
                ->preview('filament.blog.article-editor.blocks.heading')
                ->schema([
                    Forms\Components\TextInput::make('content')->label(trans_message('blog_cms.field_title'))->required(),
                    Forms\Components\Select::make('level')->label(trans_message('blog_cms.editor_field_level'))->options([2 => 'H2', 3 => 'H3', 4 => 'H4'])->default(2)->required(),
                ])
                ->columns(2),
            Builder\Block::make('list')
                ->label(fn (?array $state): string => self::resolveRepeaterBlockLabel(trans_message('blog_cms.editor_block_list'), $state, 'items'))
                ->icon('heroicon-o-list-bullet')
                ->preview('filament.blog.article-editor.blocks.list')
                ->schema([
                    Forms\Components\Select::make('style')->label(trans_message('blog_cms.editor_field_type'))->options(['unordered' => trans_message('blog_cms.editor_list_unordered'), 'ordered' => trans_message('blog_cms.editor_list_ordered')])->default('unordered')->required(),
                    Repeater::make('items')->label(trans_message('blog_cms.editor_field_items'))->schema([
                        Forms\Components\TextInput::make('value')->label(trans_message('blog_cms.editor_field_text'))->required(),
                    ])->defaultItems(2),
                ]),
            Builder\Block::make('quote')
                ->label(fn (?array $state): string => self::resolveTextBlockLabel(trans_message('blog_cms.editor_block_quote'), $state))
                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->preview('filament.blog.article-editor.blocks.quote')
                ->schema([
                    Forms\Components\Textarea::make('content')->label(trans_message('blog_cms.editor_field_text'))->rows(3)->required(),
                    Forms\Components\TextInput::make('caption')->label(trans_message('blog_cms.editor_field_caption')),
                ]),
            Builder\Block::make('image')
                ->label(fn (?array $state): string => self::resolveMediaBlockLabel(trans_message('blog_cms.editor_block_image'), $state))
                ->icon('heroicon-o-photo')
                ->preview('filament.blog.article-editor.blocks.image')
                ->schema([
                    Forms\Components\Select::make('url')->label(trans_message('blog_cms.editor_field_file'))->options(fn (): array => self::getMarketingMediaOptions())->searchable()->preload()->required(),
                    Forms\Components\TextInput::make('alt')->label(trans_message('blog_cms.field_alt_text')),
                    Forms\Components\TextInput::make('caption')->label(trans_message('blog_cms.editor_field_caption')),
                ]),
            Builder\Block::make('gallery')
                ->label(fn (?array $state): string => self::resolveRepeaterBlockLabel(trans_message('blog_cms.editor_block_gallery'), $state, 'images'))
                ->icon('heroicon-o-squares-2x2')
                ->preview('filament.blog.article-editor.blocks.gallery')
                ->schema([
                    Repeater::make('images')->label(trans_message('blog_cms.editor_field_images'))->schema([
                        Forms\Components\Select::make('url')->label(trans_message('blog_cms.editor_field_file'))->options(fn (): array => self::getMarketingMediaOptions())->searchable()->preload()->required(),
                        Forms\Components\TextInput::make('alt')->label(trans_message('blog_cms.field_alt_text')),
                    ])->columns(2)->defaultItems(2)->required(),
                ]),
            Builder\Block::make('table')
                ->label(fn (?array $state): string => self::resolveRepeaterBlockLabel(trans_message('blog_cms.editor_block_table'), $state, 'rows'))
                ->icon('heroicon-o-table-cells')
                ->preview('filament.blog.article-editor.blocks.table')
                ->schema([
                    Repeater::make('headers')->label(trans_message('blog_cms.editor_field_headers'))->schema([
                        Forms\Components\TextInput::make('value')->label(trans_message('blog_cms.editor_field_column'))->required(),
                    ])->defaultItems(2),
                    Repeater::make('rows')->label(trans_message('blog_cms.editor_field_rows'))->schema([
                        Repeater::make('cells')->label(trans_message('blog_cms.editor_field_cells'))->schema([
                            Forms\Components\TextInput::make('value')->label(trans_message('blog_cms.editor_field_value'))->required(),
                        ])->defaultItems(2),
                    ])->defaultItems(2),
                ]),
            Builder\Block::make('code')
                ->label(fn (?array $state): string => self::resolveTextBlockLabel(trans_message('blog_cms.editor_block_code'), $state))
                ->icon('heroicon-o-code-bracket')
                ->preview('filament.blog.article-editor.blocks.code')
                ->schema([
                    Forms\Components\TextInput::make('language')->label(trans_message('blog_cms.editor_field_language')),
                    Forms\Components\Textarea::make('content')->label(trans_message('blog_cms.editor_block_code'))->rows(6)->required(),
                ]),
            Builder\Block::make('divider')
                ->label(fn (?array $state): string => trans_message('blog_cms.editor_block_divider'))
                ->icon('heroicon-o-minus')
                ->preview('filament.blog.article-editor.blocks.divider')
                ->schema([]),
            Builder\Block::make('callout')
                ->label(fn (?array $state): string => self::resolveTextBlockLabel(trans_message('blog_cms.editor_block_callout'), $state, 'title'))
                ->icon('heroicon-o-megaphone')
                ->preview('filament.blog.article-editor.blocks.callout')
                ->schema([
                    Forms\Components\Select::make('variant')->label(trans_message('blog_cms.editor_field_style'))->options([
                        'info' => trans_message('blog_cms.editor_block_variant_info'),
                        'success' => trans_message('blog_cms.editor_block_variant_success'),
                        'warning' => trans_message('blog_cms.editor_block_variant_warning'),
                    ])->default('info'),
                    Forms\Components\TextInput::make('title')->label(trans_message('blog_cms.field_title')),
                    Forms\Components\Textarea::make('content')->label(trans_message('blog_cms.editor_field_text'))->rows(3),
                ]),
            Builder\Block::make('embed')
                ->label(fn (?array $state): string => self::resolveTextBlockLabel(trans_message('blog_cms.editor_block_embed'), $state, 'url'))
                ->icon('heroicon-o-play-circle')
                ->preview('filament.blog.article-editor.blocks.embed')
                ->schema([
                    Forms\Components\TextInput::make('url')->label(trans_message('blog_cms.editor_field_link'))->required()->url(),
                ]),
            Builder\Block::make('cta')
                ->label(fn (?array $state): string => self::resolveTextBlockLabel(trans_message('blog_cms.editor_block_cta'), $state, 'label'))
                ->icon('heroicon-o-cursor-arrow-rays')
                ->preview('filament.blog.article-editor.blocks.cta')
                ->schema([
                    Forms\Components\TextInput::make('label')->label(trans_message('blog_cms.editor_field_button_text'))->required(),
                    Forms\Components\TextInput::make('url')->label(trans_message('blog_cms.editor_field_link'))->required(),
                    Forms\Components\Textarea::make('description')->label(trans_message('blog_cms.editor_field_description'))->rows(2),
                ]),
        ];
    }

    private static function resolveTextBlockLabel(string $defaultLabel, ?array $state, string $field = 'content'): string
    {
        if ($state === null) {
            return $defaultLabel;
        }

        $value = trim((string) Arr::get($state, $field, ''));

        return $value !== '' ? Str::limit($value, 56) : $defaultLabel;
    }

    private static function resolveRepeaterBlockLabel(string $defaultLabel, ?array $state, string $field): string
    {
        if ($state === null) {
            return $defaultLabel;
        }

        $count = count(Arr::wrap(Arr::get($state, $field, [])));

        return $count > 0 ? $defaultLabel.' · '.$count : $defaultLabel;
    }

    private static function resolveMediaBlockLabel(string $defaultLabel, ?array $state): string
    {
        if ($state === null) {
            return $defaultLabel;
        }

        $value = trim((string) Arr::get($state, 'caption', Arr::get($state, 'alt', '')));

        return $value !== '' ? Str::limit($value, 56) : $defaultLabel;
    }

    /**
     * @return array<string, string>
     */
    private static function getMarketingMediaOptions(): array
    {
        return BlogMediaAsset::query()
            ->where('blog_context', 'marketing')
            ->latest()
            ->pluck('filename', 'public_url')
            ->all();
    }
}
