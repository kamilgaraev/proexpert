<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\BlogArticleResource\Pages;
use App\Filament\Resources\BlogArticleResource\RelationManagers\BlogArticleRevisionsRelationManager;
use App\Models\Blog\BlogArticle;
use App\Models\Blog\BlogCategory;
use App\Models\Blog\BlogMediaAsset;
use App\Models\Blog\BlogTag;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\ViewField;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class BlogArticleResource extends Resource
{
    protected static ?string $model = BlogArticle::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-document-text';

    protected static string | \UnitEnum | null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return 'Блог';
    }

    public static function getModelLabel(): string
    {
        return 'статья';
    }

    public static function getPluralModelLabel(): string
    {
        return 'статьи';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Редактор статьи')
                    ->description('Полотно контента, блоки и быстрые действия редактора.')
                    ->schema([
                        ViewField::make('editor_workspace_overview')
                            ->view('filament.blog.article-editor.workspace-overview')
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
                            ->blocks(self::editorBlocks())
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

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->marketing()->with(['category', 'systemAuthor']))
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Статья')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('category.name')
                    ->label('Категория')
                    ->badge(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge(),
                Tables\Columns\TextColumn::make('systemAuthor.name')
                    ->label('Автор')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('published_at')
                    ->label('Публикация')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_autosaved_at')
                    ->label('Автосохранение')
                    ->since()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'draft' => 'Черновик',
                        'published' => 'Опубликована',
                        'scheduled' => 'Запланирована',
                        'archived' => 'Архив',
                    ]),
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('Категория')
                    ->options(fn (): array => BlogCategory::query()->marketing()->pluck('name', 'id')->all()),
            ])
            ->actions([
                EditAction::make()->label('Открыть редактор'),
                DeleteAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            BlogArticleRevisionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBlogArticles::route('/'),
            'create' => Pages\CreateBlogArticle::route('/create'),
            'edit' => Pages\EditBlogArticle::route('/{record}/edit'),
        ];
    }

    protected static function editorBlocks(): array
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

    private static function getMarketingMediaOptions(): array
    {
        return BlogMediaAsset::query()
            ->where('blog_context', 'marketing')
            ->latest()
            ->pluck('filename', 'public_url')
            ->all();
    }
}
