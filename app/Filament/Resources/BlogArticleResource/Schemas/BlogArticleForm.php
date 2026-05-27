<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlogArticleResource\Schemas;

use App\Models\Blog\BlogCategory;
use App\Models\Blog\BlogMediaAsset;
use App\Models\Blog\BlogTag;
use Filament\Forms;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;

final class BlogArticleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Редактор статьи')
                    ->description('Полотно контента, блоки и быстрые действия редактора.')
                    ->schema([
                        ViewField::make('editor_workspace_overview')
                            ->view('filament.blog.article-editor.workspace-overview')
                            ->hiddenLabel()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('title')
                            ->label('Заголовок')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true),
                        Forms\Components\TextInput::make('slug')
                            ->label('Slug')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Textarea::make('excerpt')
                            ->label('Лид')
                            ->rows(4)
                            ->placeholder('Короткое резюме статьи для листинга, SEO и превью.')
                            ->columnSpanFull(),
                        Builder::make('editor_document')
                            ->label('Полотно статьи')
                            ->blocks(BlogEditorBlocks::blocks())
                            ->blockIcons()
                            ->blockPreviews()
                            ->blockNumbers(false)
                            ->collapsible()
                            ->cloneable()
                            ->reorderableWithButtons()
                            ->addActionAlignment(Alignment::Start)
                            ->addActionLabel('Добавить блок')
                            ->helperText('Нажмите на карточку блока, чтобы открыть его настройки. Ctrl/Cmd+S запускает autosave.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpan(2),
                Section::make('Публикация')
                    ->description('Статус, тайминг публикации и редакторский health-check.')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('Статус')
                            ->options([
                                'draft' => 'Черновик',
                                'published' => 'Опубликована',
                                'scheduled' => 'Запланирована',
                                'archived' => 'Архив',
                            ])
                            ->default('draft')
                            ->required(),
                        Forms\Components\DateTimePicker::make('published_at')
                            ->label('Дата публикации'),
                        Forms\Components\DateTimePicker::make('scheduled_at')
                            ->label('План публикации'),
                        Forms\Components\TextInput::make('sort_order')
                            ->label('Сортировка')
                            ->numeric()
                            ->default(0),
                        Forms\Components\Toggle::make('is_featured')
                            ->label('Выделить статью'),
                        Forms\Components\Toggle::make('allow_comments')
                            ->label('Разрешить комментарии')
                            ->default(true),
                        Forms\Components\Toggle::make('is_published_in_rss')
                            ->label('Показывать в RSS')
                            ->default(true),
                        Forms\Components\Toggle::make('noindex')
                            ->label('Noindex'),
                        ViewField::make('editor_outline')
                            ->view('filament.blog.article-editor.outline')
                            ->hiddenLabel()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ])
                    ->columns(1),
                Section::make('Таксономия и медиа')
                    ->description('Категории, теги, обложка и галерея для выдачи статьи.')
                    ->schema([
                        Forms\Components\Select::make('category_id')
                            ->label('Категория')
                            ->options(fn (): array => BlogCategory::query()->marketing()->orderBy('sort_order')->pluck('name', 'id')->all())
                            ->searchable()
                            ->preload(),
                        Forms\Components\Select::make('tag_ids')
                            ->label('Теги')
                            ->options(fn (): array => BlogTag::query()->marketing()->orderBy('name')->pluck('name', 'id')->all())
                            ->multiple()
                            ->searchable()
                            ->preload(),
                        Forms\Components\Select::make('featured_image')
                            ->label('Обложка')
                            ->options(fn (): array => self::getMarketingMediaOptions())
                            ->searchable()
                            ->preload(),
                        Forms\Components\Select::make('gallery_images')
                            ->label('Галерея')
                            ->options(fn (): array => self::getMarketingMediaOptions())
                            ->multiple()
                            ->searchable()
                            ->preload(),
                    ])
                    ->columns(1),
                Section::make('SEO и Open Graph')
                    ->description('Метаданные, которые нужны для публикации и красивого превью в поиске.')
                    ->schema([
                        Forms\Components\TextInput::make('meta_title')
                            ->label('Meta title')
                            ->maxLength(255),
                        Forms\Components\Textarea::make('meta_description')
                            ->label('Meta description')
                            ->rows(3),
                        Forms\Components\TagsInput::make('meta_keywords')
                            ->label('Meta keywords'),
                        Forms\Components\TextInput::make('og_title')
                            ->label('OG title'),
                        Forms\Components\Textarea::make('og_description')
                            ->label('OG description')
                            ->rows(3),
                        Forms\Components\Select::make('og_image')
                            ->label('OG image')
                            ->options(fn (): array => self::getMarketingMediaOptions())
                            ->searchable()
                            ->preload(),
                    ])
                    ->columns(1),
            ])
            ->columns(3);
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
