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
                ->label(fn (?array $state): string => self::resolveTextBlockLabel('Абзац', $state))
                ->icon('heroicon-o-bars-3-bottom-left')
                ->preview('filament.blog.article-editor.blocks.paragraph')
                ->schema([
                    Forms\Components\Textarea::make('content')->label('Текст')->rows(5)->required(),
                ]),
            Builder\Block::make('heading')
                ->label(fn (?array $state): string => self::resolveTextBlockLabel('Заголовок', $state))
                ->icon('heroicon-o-hashtag')
                ->preview('filament.blog.article-editor.blocks.heading')
                ->schema([
                    Forms\Components\TextInput::make('content')->label('Заголовок')->required(),
                    Forms\Components\Select::make('level')->label('Уровень')->options([2 => 'H2', 3 => 'H3', 4 => 'H4'])->default(2)->required(),
                ])
                ->columns(2),
            Builder\Block::make('list')
                ->label(fn (?array $state): string => self::resolveRepeaterBlockLabel('Список', $state, 'items'))
                ->icon('heroicon-o-list-bullet')
                ->preview('filament.blog.article-editor.blocks.list')
                ->schema([
                    Forms\Components\Select::make('style')->label('Тип')->options(['unordered' => 'Маркированный', 'ordered' => 'Нумерованный'])->default('unordered')->required(),
                    Repeater::make('items')->label('Пункты')->schema([
                        Forms\Components\TextInput::make('value')->label('Текст')->required(),
                    ])->defaultItems(2),
                ]),
            Builder\Block::make('quote')
                ->label(fn (?array $state): string => self::resolveTextBlockLabel('Цитата', $state))
                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->preview('filament.blog.article-editor.blocks.quote')
                ->schema([
                    Forms\Components\Textarea::make('content')->label('Текст')->rows(3)->required(),
                    Forms\Components\TextInput::make('caption')->label('Подпись'),
                ]),
            Builder\Block::make('image')
                ->label(fn (?array $state): string => self::resolveMediaBlockLabel('Изображение', $state))
                ->icon('heroicon-o-photo')
                ->preview('filament.blog.article-editor.blocks.image')
                ->schema([
                    Forms\Components\Select::make('url')->label('Файл')->options(fn (): array => self::getMarketingMediaOptions())->searchable()->preload()->required(),
                    Forms\Components\TextInput::make('alt')->label('Alt'),
                    Forms\Components\TextInput::make('caption')->label('Подпись'),
                ]),
            Builder\Block::make('gallery')
                ->label(fn (?array $state): string => self::resolveRepeaterBlockLabel('Галерея', $state, 'images'))
                ->icon('heroicon-o-squares-2x2')
                ->preview('filament.blog.article-editor.blocks.gallery')
                ->schema([
                    Repeater::make('images')->label('Изображения')->schema([
                        Forms\Components\Select::make('url')->label('Файл')->options(fn (): array => self::getMarketingMediaOptions())->searchable()->preload()->required(),
                        Forms\Components\TextInput::make('alt')->label('Alt'),
                    ])->columns(2)->defaultItems(2)->required(),
                ]),
            Builder\Block::make('table')
                ->label(fn (?array $state): string => self::resolveRepeaterBlockLabel('Таблица', $state, 'rows'))
                ->icon('heroicon-o-table-cells')
                ->preview('filament.blog.article-editor.blocks.table')
                ->schema([
                    Repeater::make('headers')->label('Заголовки')->schema([
                        Forms\Components\TextInput::make('value')->label('Колонка')->required(),
                    ])->defaultItems(2),
                    Repeater::make('rows')->label('Строки')->schema([
                        Repeater::make('cells')->label('Ячейки')->schema([
                            Forms\Components\TextInput::make('value')->label('Значение')->required(),
                        ])->defaultItems(2),
                    ])->defaultItems(2),
                ]),
            Builder\Block::make('code')
                ->label(fn (?array $state): string => self::resolveTextBlockLabel('Код', $state))
                ->icon('heroicon-o-code-bracket')
                ->preview('filament.blog.article-editor.blocks.code')
                ->schema([
                    Forms\Components\TextInput::make('language')->label('Язык'),
                    Forms\Components\Textarea::make('content')->label('Код')->rows(6)->required(),
                ]),
            Builder\Block::make('divider')
                ->label(fn (?array $state): string => 'Разделитель')
                ->icon('heroicon-o-minus')
                ->preview('filament.blog.article-editor.blocks.divider')
                ->schema([]),
            Builder\Block::make('callout')
                ->label(fn (?array $state): string => self::resolveTextBlockLabel('Callout', $state, 'title'))
                ->icon('heroicon-o-megaphone')
                ->preview('filament.blog.article-editor.blocks.callout')
                ->schema([
                    Forms\Components\Select::make('variant')->label('Стиль')->options(['info' => 'Info', 'success' => 'Success', 'warning' => 'Warning'])->default('info'),
                    Forms\Components\TextInput::make('title')->label('Заголовок'),
                    Forms\Components\Textarea::make('content')->label('Текст')->rows(3),
                ]),
            Builder\Block::make('embed')
                ->label(fn (?array $state): string => self::resolveTextBlockLabel('Embed', $state, 'url'))
                ->icon('heroicon-o-play-circle')
                ->preview('filament.blog.article-editor.blocks.embed')
                ->schema([
                    Forms\Components\TextInput::make('url')->label('Ссылка')->required()->url(),
                ]),
            Builder\Block::make('cta')
                ->label(fn (?array $state): string => self::resolveTextBlockLabel('CTA', $state, 'label'))
                ->icon('heroicon-o-cursor-arrow-rays')
                ->preview('filament.blog.article-editor.blocks.cta')
                ->schema([
                    Forms\Components\TextInput::make('label')->label('Текст кнопки')->required(),
                    Forms\Components\TextInput::make('url')->label('Ссылка')->required(),
                    Forms\Components\Textarea::make('description')->label('Описание')->rows(2),
                ]),
        ];
    }

    private static function resolveTextBlockLabel(string $fallback, ?array $state, string $field = 'content'): string
    {
        if ($state === null) {
            return $fallback;
        }

        $value = trim((string) Arr::get($state, $field, ''));

        return $value !== '' ? Str::limit($value, 56) : $fallback;
    }

    private static function resolveRepeaterBlockLabel(string $fallback, ?array $state, string $field): string
    {
        if ($state === null) {
            return $fallback;
        }

        $count = count(Arr::wrap(Arr::get($state, $field, [])));

        return $count > 0 ? $fallback . ' · ' . $count : $fallback;
    }

    private static function resolveMediaBlockLabel(string $fallback, ?array $state): string
    {
        if ($state === null) {
            return $fallback;
        }

        $value = trim((string) Arr::get($state, 'caption', Arr::get($state, 'alt', '')));

        return $value !== '' ? Str::limit($value, 56) : $fallback;
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
