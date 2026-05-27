<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlogArticleResource\Schemas;

use App\Enums\Blog\BlogArticleStatusEnum;
use App\Models\Blog\BlogArticle;
use App\Models\Blog\BlogCategory;
use App\Models\Blog\BlogMediaAsset;
use App\Models\Blog\BlogTag;
use App\Models\SystemAdmin;
use Filament\Forms;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

final class BlogArticleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Заголовок и адрес')
                    ->description('Основные поля статьи и человекочитаемая ссылка.')
                    ->schema([
                        ViewField::make('editor_workspace_overview')
                            ->view('filament.blog.article-editor.workspace-overview')
                            ->hiddenLabel()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('title')
                            ->label('Заголовок')
                            ->required()
                            ->minLength(3)
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state, ?string $old): void {
                                $currentSlug = (string) $get('slug');
                                $previousTitleSlug = Str::slug((string) $old);

                                if ($currentSlug !== '' && $currentSlug !== $previousTitleSlug) {
                                    return;
                                }

                                $set('slug', Str::slug((string) $state));
                            }),
                        Forms\Components\TextInput::make('slug')
                            ->label('Slug')
                            ->required()
                            ->maxLength(255)
                            ->dehydrateStateUsing(fn (?string $state): string => Str::slug((string) $state))
                            ->unique(BlogArticle::class, 'slug', ignoreRecord: true),
                        Forms\Components\TextInput::make('canonical_url')
                            ->label(trans_message('blog_cms.field_canonical_url'))
                            ->url()
                            ->maxLength(2048)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpan(2),
                Section::make('Текст и краткое описание')
                    ->description('Лид, структура и основное полотно материала.')
                    ->schema([
                        Forms\Components\Textarea::make('excerpt')
                            ->label('Лид')
                            ->rows(4)
                            ->maxLength(500)
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
                            ->helperText('Соберите статью из блоков: текст, заголовки, списки, цитаты, изображения, таблицы и призыв к действию.')
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->columnSpan(2),
                Section::make('Публикация')
                    ->description('Статус, даты выхода и параметры показа.')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('Статус')
                            ->options([
                                BlogArticleStatusEnum::DRAFT->value => 'Черновик',
                                BlogArticleStatusEnum::PUBLISHED->value => 'Опубликована',
                                BlogArticleStatusEnum::SCHEDULED->value => 'Запланирована',
                                BlogArticleStatusEnum::ARCHIVED->value => 'Архив',
                            ])
                            ->default('draft')
                            ->live()
                            ->required(),
                        Forms\Components\DateTimePicker::make('published_at')
                            ->label('Дата публикации')
                            ->rules(['nullable', 'date', 'before_or_equal:now']),
                        Forms\Components\DateTimePicker::make('scheduled_at')
                            ->label('План публикации')
                            ->rules([
                                fn (Get $get): string => $get('status') === BlogArticleStatusEnum::SCHEDULED->value
                                    ? 'required|date|after:now'
                                    : 'nullable|date',
                            ]),
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
                            ->label(trans_message('blog_cms.field_noindex')),
                        ViewField::make('editor_outline')
                            ->view('filament.blog.article-editor.outline')
                            ->hiddenLabel()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ])
                    ->columns(1),
                Section::make(trans_message('blog_cms.editorial_checklist_section'))
                    ->schema([
                        ViewField::make('editorial_checklist')
                            ->view('filament.blog.article-editor.editorial-checklist')
                            ->hiddenLabel()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ])
                    ->columns(1),
                Section::make('Автор и категория')
                    ->description('Редакционная принадлежность статьи и тематическая навигация.')
                    ->schema([
                        Forms\Components\Select::make('author_system_admin_id')
                            ->label('Автор')
                            ->options(fn (): array => SystemAdmin::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->default(fn (): ?int => Auth::guard('system_admin')->id())
                            ->searchable()
                            ->preload()
                            ->required(),
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
                    ])
                    ->columns(1),
                Section::make('Обложка и медиа')
                    ->description('Основное изображение и дополнительные материалы статьи.')
                    ->schema([
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
                Section::make('Внутренние заметки')
                    ->description('Редакционный контекст, который не показывается читателям.')
                    ->schema([
                        Forms\Components\Textarea::make('editor_notes')
                            ->label(trans_message('blog_cms.field_editor_notes'))
                            ->rows(5)
                            ->maxLength(5000)
                            ->columnSpanFull(),
                    ])
                    ->columns(1),
                Section::make('SEO и Open Graph')
                    ->description('Метаданные, которые нужны для публикации и красивого превью в поиске.')
                    ->schema([
                        Forms\Components\TextInput::make('meta_title')
                            ->label(trans_message('blog_cms.field_seo_title'))
                            ->maxLength(255),
                        Forms\Components\Textarea::make('meta_description')
                            ->label(trans_message('blog_cms.field_seo_description'))
                            ->rows(3),
                        Forms\Components\TagsInput::make('meta_keywords')
                            ->label(trans_message('blog_cms.field_seo_keywords')),
                        Forms\Components\TextInput::make('og_title')
                            ->label(trans_message('blog_cms.field_open_graph_title')),
                        Forms\Components\Textarea::make('og_description')
                            ->label(trans_message('blog_cms.field_open_graph_description'))
                            ->rows(3),
                        Forms\Components\Select::make('og_image')
                            ->label(trans_message('blog_cms.field_open_graph_image'))
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
