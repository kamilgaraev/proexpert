<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlogArticleResource\Schemas;

use App\Enums\Blog\BlogArticleStatusEnum;
use App\Filament\Forms\Components\BlogInlineBlockEditor;
use App\Filament\Resources\BlogMediaAssetResource;
use App\Models\Blog\BlogArticle;
use App\Models\Blog\BlogCategory;
use App\Models\Blog\BlogMediaAsset;
use App\Models\Blog\BlogTag;
use App\Models\SystemAdmin;
use App\Services\Blog\BlogMediaService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

final class BlogArticleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(trans_message('blog_cms.form_section_title_address'))
                    ->description(trans_message('blog_cms.form_section_title_address_description'))
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label(trans_message('blog_cms.field_title'))
                            ->required()
                            ->minLength(3)
                            ->maxLength(255)
                            ->helperText(trans_message('blog_cms.helper_title'))
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
                            ->label(trans_message('blog_cms.field_slug'))
                            ->required()
                            ->maxLength(255)
                            ->helperText(trans_message('blog_cms.helper_slug'))
                            ->dehydrateStateUsing(fn (?string $state): string => Str::slug((string) $state))
                            ->unique(BlogArticle::class, 'slug', ignoreRecord: true),
                        Forms\Components\TextInput::make('canonical_url')
                            ->label(trans_message('blog_cms.field_canonical_url'))
                            ->url()
                            ->maxLength(2048)
                            ->helperText(trans_message('blog_cms.helper_canonical_url'))
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpan(2),
                Section::make(trans_message('blog_cms.form_section_publication'))
                    ->description(trans_message('blog_cms.form_section_publication_description'))
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label(trans_message('blog_cms.field_status'))
                            ->options([
                                BlogArticleStatusEnum::DRAFT->value => trans_message('blog_cms.article_statuses.draft'),
                                BlogArticleStatusEnum::PUBLISHED->value => trans_message('blog_cms.article_statuses.published'),
                                BlogArticleStatusEnum::SCHEDULED->value => trans_message('blog_cms.article_statuses.scheduled'),
                                BlogArticleStatusEnum::ARCHIVED->value => trans_message('blog_cms.article_statuses.archived'),
                            ])
                            ->default('draft')
                            ->live()
                            ->helperText(trans_message('blog_cms.helper_status'))
                            ->required(),
                        Forms\Components\DateTimePicker::make('published_at')
                            ->label(trans_message('blog_cms.field_published_at'))
                            ->helperText(trans_message('blog_cms.helper_published_at'))
                            ->rules(['nullable', 'date', 'before_or_equal:now']),
                        Forms\Components\DateTimePicker::make('scheduled_at')
                            ->label(trans_message('blog_cms.field_scheduled_plan'))
                            ->helperText(trans_message('blog_cms.helper_scheduled_at'))
                            ->rules([
                                fn (Get $get): string => $get('status') === BlogArticleStatusEnum::SCHEDULED->value
                                    ? 'required|date|after:now'
                                    : 'nullable|date',
                            ]),
                        Forms\Components\TextInput::make('sort_order')
                            ->label(trans_message('blog_cms.field_sort_order'))
                            ->numeric()
                            ->default(0),
                        Forms\Components\Toggle::make('is_featured')
                            ->label(trans_message('blog_cms.field_is_featured')),
                        Forms\Components\Toggle::make('allow_comments')
                            ->label(trans_message('blog_cms.field_allow_comments'))
                            ->default(true),
                        Forms\Components\Toggle::make('is_published_in_rss')
                            ->label(trans_message('blog_cms.field_rss_visibility'))
                            ->default(true),
                        Forms\Components\Toggle::make('noindex')
                            ->label(trans_message('blog_cms.field_noindex'))
                            ->live(),
                        ViewField::make('editor_outline')
                            ->view('filament.blog.article-editor.outline')
                            ->hiddenLabel()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->hiddenOn(Operation::Create),
                Section::make(trans_message('blog_cms.form_section_content'))
                    ->description(trans_message('blog_cms.form_section_content_description'))
                    ->schema([
                        Forms\Components\Textarea::make('excerpt')
                            ->label(trans_message('blog_cms.field_excerpt'))
                            ->rows(4)
                            ->maxLength(500)
                            ->placeholder(trans_message('blog_cms.placeholder_excerpt'))
                            ->helperText(trans_message('blog_cms.helper_excerpt'))
                            ->columnSpanFull(),
                        BlogInlineBlockEditor::make('editor_document')
                            ->label(trans_message('blog_cms.field_editor_document'))
                            ->blockDefinitions(fn (): array => BlogEditorBlockCatalog::forEditor())
                            ->mediaOptions(fn (): array => self::getMarketingMediaOptions())
                            ->acceptedImageTypes(BlogMediaService::allowedImageMimeTypes())
                            ->helperText(trans_message('blog_cms.helper_editor_document'))
                            ->hiddenOn(Operation::Create)
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->columnSpan(2),
                Section::make(trans_message('blog_cms.editorial_checklist_section'))
                    ->schema([
                        ViewField::make('editorial_checklist')
                            ->view('filament.blog.article-editor.editorial-checklist')
                            ->hiddenLabel()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->hiddenOn(Operation::Create),
                Section::make(trans_message('blog_cms.form_section_author_category'))
                    ->description(trans_message('blog_cms.form_section_author_category_description'))
                    ->schema([
                        Forms\Components\Select::make('author_system_admin_id')
                            ->label(trans_message('blog_cms.field_author'))
                            ->options(fn (): array => SystemAdmin::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->default(fn (): ?int => Auth::guard('system_admin')->id())
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\Select::make('category_id')
                            ->label(trans_message('blog_cms.field_category'))
                            ->options(fn (): array => BlogCategory::query()->marketing()->orderBy('sort_order')->pluck('name', 'id')->all())
                            ->helperText(trans_message('blog_cms.helper_category'))
                            ->searchable()
                            ->preload(),
                        Forms\Components\Select::make('tag_ids')
                            ->label(trans_message('blog_cms.field_tags'))
                            ->options(fn (): array => BlogTag::query()->marketing()->orderBy('name')->pluck('name', 'id')->all())
                            ->multiple()
                            ->searchable()
                            ->preload(),
                    ])
                    ->columns(1)
                    ->collapsible(),
                Section::make(trans_message('blog_cms.form_section_media'))
                    ->description(trans_message('blog_cms.form_section_media_description'))
                    ->schema([
                        self::configureMarketingMediaSelect(Forms\Components\Select::make('featured_image'))
                            ->label(trans_message('blog_cms.field_featured_image'))
                            ->helperText(trans_message('blog_cms.helper_featured_image'))
                            ->live()
                            ->searchable()
                            ->preload(),
                        self::configureMarketingMediaSelect(Forms\Components\Select::make('gallery_images'))
                            ->label(trans_message('blog_cms.field_gallery'))
                            ->multiple()
                            ->searchable()
                            ->preload(),
                    ])
                    ->columns(1)
                    ->collapsible()
                    ->hiddenOn(Operation::Create),
                Section::make(trans_message('blog_cms.form_section_editor_notes'))
                    ->description(trans_message('blog_cms.form_section_editor_notes_description'))
                    ->schema([
                        Forms\Components\Textarea::make('editor_notes')
                            ->label(trans_message('blog_cms.field_editor_notes'))
                            ->rows(5)
                            ->maxLength(5000)
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->collapsible()
                    ->collapsed()
                    ->hiddenOn(Operation::Create),
                Section::make(trans_message('blog_cms.form_section_seo'))
                    ->description(trans_message('blog_cms.form_section_seo_description'))
                    ->schema([
                        Forms\Components\TextInput::make('meta_title')
                            ->label(trans_message('blog_cms.field_seo_title'))
                            ->helperText(trans_message('blog_cms.helper_meta_title'))
                            ->live(onBlur: true)
                            ->maxLength(255),
                        Forms\Components\Textarea::make('meta_description')
                            ->label(trans_message('blog_cms.field_seo_description'))
                            ->rows(3)
                            ->live(onBlur: true)
                            ->helperText(trans_message('blog_cms.helper_meta_description')),
                        Forms\Components\TagsInput::make('meta_keywords')
                            ->label(trans_message('blog_cms.field_seo_keywords')),
                        Forms\Components\TextInput::make('og_title')
                            ->label(trans_message('blog_cms.field_open_graph_title'))
                            ->live(onBlur: true),
                        Forms\Components\Textarea::make('og_description')
                            ->label(trans_message('blog_cms.field_open_graph_description'))
                            ->rows(3)
                            ->live(onBlur: true),
                        self::configureMarketingMediaSelect(Forms\Components\Select::make('og_image'))
                            ->label(trans_message('blog_cms.field_open_graph_image'))
                            ->live()
                            ->searchable()
                            ->preload(),
                        ViewField::make('seo_preview')
                            ->view('filament.blog.article-editor.seo-preview')
                            ->hiddenLabel()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->collapsible()
                    ->hiddenOn(Operation::Create),
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
            ->whereIn('mime_type', BlogMediaService::allowedImageMimeTypes())
            ->latest()
            ->pluck('filename', 'public_url')
            ->all();
    }

    private static function configureMarketingMediaSelect(Forms\Components\Select $select): Forms\Components\Select
    {
        return $select
            ->options(fn (): array => self::getMarketingMediaOptions())
            ->getOptionLabelUsing(fn (?string $value): ?string => self::getMarketingMediaLabel($value))
            ->getOptionLabelsUsing(fn (array $values): array => self::getMarketingMediaLabels($values))
            ->createOptionForm(BlogMediaAssetResource::uploadFormSchema(imagesOnly: true))
            ->createOptionUsing(fn (array $data): string => self::createMarketingImageOption($data))
            ->createOptionModalHeading(trans_message('blog_cms.media_upload_modal_heading'))
            ->createOptionAction(fn (Action $action): Action => $action
                ->label(trans_message('blog_cms.media_upload_action'))
                ->modalSubmitActionLabel(trans_message('blog_cms.media_upload_submit'))
                ->visible(fn (): bool => self::canUploadMedia()));
    }

    private static function createMarketingImageOption(array $data): string
    {
        $file = $data['upload_file'] ?? null;

        if (! $file instanceof TemporaryUploadedFile) {
            throw ValidationException::withMessages([
                'upload_file' => [trans_message('blog_cms.media_upload_required')],
            ]);
        }

        /** @var SystemAdmin $systemAdmin */
        $systemAdmin = Auth::guard('system_admin')->user();

        if (! $systemAdmin instanceof SystemAdmin || ! $systemAdmin->hasSystemPermission('system_admin.blog.media.upload')) {
            throw ValidationException::withMessages([
                'upload_file' => [trans_message('blog_cms.media_upload_forbidden')],
            ]);
        }

        $asset = app(BlogMediaService::class)->uploadMarketingImageAsset($file, $systemAdmin, [
            'alt_text' => $data['alt_text'] ?? null,
            'caption' => $data['caption'] ?? null,
            'focal_point' => $data['focal_point'] ?? null,
        ]);

        return $asset->public_url;
    }

    private static function getMarketingMediaLabel(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return BlogMediaAsset::query()
            ->where('blog_context', 'marketing')
            ->whereIn('mime_type', BlogMediaService::allowedImageMimeTypes())
            ->where('public_url', $value)
            ->value('filename');
    }

    /**
     * @param array<int, mixed> $values
     *
     * @return array<string, string>
     */
    private static function getMarketingMediaLabels(array $values): array
    {
        $urls = collect($values)
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();

        if ($urls === []) {
            return [];
        }

        return BlogMediaAsset::query()
            ->where('blog_context', 'marketing')
            ->whereIn('mime_type', BlogMediaService::allowedImageMimeTypes())
            ->whereIn('public_url', $urls)
            ->pluck('filename', 'public_url')
            ->all();
    }

    private static function canUploadMedia(): bool
    {
        $systemAdmin = Auth::guard('system_admin')->user();

        return $systemAdmin instanceof SystemAdmin
            && $systemAdmin->hasSystemPermission('system_admin.blog.media.upload');
    }
}
