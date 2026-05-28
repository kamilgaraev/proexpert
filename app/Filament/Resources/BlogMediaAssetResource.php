<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\BlogMediaAssetResource\Pages;
use App\Filament\Support\Concerns\AuthorizesSystemAdminResource;
use App\Filament\Support\Concerns\HasDestructiveActionGuardrails;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\TableEmptyState;
use App\Models\Blog\BlogMediaAsset;
use App\Models\SystemAdmin;
use App\Policies\SystemAdmin\BlogMediaAssetPolicy;
use App\Services\Blog\BlogMediaService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class BlogMediaAssetResource extends Resource
{
    use AuthorizesSystemAdminResource;
    use HasDestructiveActionGuardrails;

    protected static ?string $model = BlogMediaAsset::class;

    protected static string $systemAdminPolicy = BlogMediaAssetPolicy::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return NavigationGroups::blog();
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('blog_cms.media_navigation_label');
    }

    public static function getModelLabel(): string
    {
        return trans_message('blog_cms.media_model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans_message('blog_cms.media_plural_model_label');
    }

    public static function hasTitleCaseModelLabel(): bool
    {
        return false;
    }

    public static function getBreadcrumb(): string
    {
        return trans_message('blog_cms.media_list_title');
    }

    public static function uploadFormSchema(
        string $fileFieldName = 'upload_file',
        bool|Closure $fileRequired = true,
        bool $imagesOnly = false,
    ): array
    {
        return [
            Forms\Components\FileUpload::make($fileFieldName)
                ->label(trans_message('blog_cms.media_field_file'))
                ->acceptedFileTypes($imagesOnly ? BlogMediaService::allowedImageMimeTypes() : BlogMediaService::allowedMimeTypes())
                ->maxSize(BlogMediaService::maxUploadSizeKilobytes())
                ->imageEditor()
                ->panelLayout('grid')
                ->imagePreviewHeight('12rem')
                ->uploadingMessage(trans_message('blog_cms.media_uploading'))
                ->helperText(trans_message($imagesOnly ? 'blog_cms.media_image_file_helper' : 'blog_cms.media_file_helper'))
                ->storeFiles(false)
                ->required($fileRequired)
                ->columnSpanFull(),
            Forms\Components\TextInput::make('alt_text')
                ->label(trans_message('blog_cms.field_alt_text'))
                ->required(),
            Forms\Components\TextInput::make('caption')
                ->label(trans_message('blog_cms.media_field_caption')),
            Forms\Components\TextInput::make('focal_point.x')
                ->label(trans_message('blog_cms.field_focal_x'))
                ->numeric()
                ->minValue(0)
                ->maxValue(1),
            Forms\Components\TextInput::make('focal_point.y')
                ->label(trans_message('blog_cms.field_focal_y'))
                ->numeric()
                ->minValue(0)
                ->maxValue(1),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(trans_message('blog_cms.media_form_section_file'))
                ->description(trans_message('blog_cms.media_form_section_file_description'))
                ->schema(self::uploadFormSchema(
                    fileRequired: fn (string $operation): bool => $operation === 'create',
                ))
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return TableEmptyState::for($table, 'blog_media_assets', 'heroicon-o-photo')
            ->modifyQueryUsing(fn ($query) => $query->where('blog_context', 'marketing'))
            ->columns([
                Tables\Columns\ImageColumn::make('public_url')->label(trans_message('blog_cms.media_field_preview')),
                Tables\Columns\TextColumn::make('filename')->label(trans_message('blog_cms.media_field_filename'))->searchable(),
                Tables\Columns\TextColumn::make('mime_type')->label(trans_message('blog_cms.media_field_mime')),
                Tables\Columns\TextColumn::make('file_size')
                    ->label(trans_message('blog_cms.media_field_size'))
                    ->formatStateUsing(fn (?int $state): string => self::formatFileSize($state)),
                Tables\Columns\TextColumn::make('usage_metadata.count')->label(trans_message('blog_cms.media_field_usage_count'))->default(0),
                Tables\Columns\TextColumn::make('updated_at')->label(trans_message('blog_cms.media_field_updated_at'))->since(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('safe_replace')
                        ->label(trans_message('blog_cms.media_replace_action'))
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->schema(self::uploadFormSchema(fileFieldName: 'replacement_file'))
                        ->visible(fn (): bool => Auth::guard('system_admin')->user()?->hasSystemPermission('system_admin.blog.media.manage') ?? false)
                        ->action(function (array $data, BlogMediaAsset $record): void {
                            /** @var SystemAdmin $systemAdmin */
                            $systemAdmin = Auth::guard('system_admin')->user();
                            $file = $data['replacement_file'] ?? null;

                            if (! $file instanceof TemporaryUploadedFile) {
                                return;
                            }

                            app(BlogMediaService::class)->replaceWithUploadedFile($record, $file, $systemAdmin, [
                                'alt_text' => $data['alt_text'] ?? null,
                                'caption' => $data['caption'] ?? null,
                                'focal_point' => $data['focal_point'] ?? null,
                            ]);

                            Notification::make()
                                ->success()
                                ->title(trans_message('blog_cms.media_replace_done'))
                                ->send();
                        }),
                    self::guardedDeleteAction('media_asset'),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBlogMediaAssets::route('/'),
            'create' => Pages\CreateBlogMediaAsset::route('/create'),
            'edit' => Pages\EditBlogMediaAsset::route('/{record}/edit'),
        ];
    }

    private static function formatFileSize(?int $size): string
    {
        if ($size === null || $size <= 0) {
            return trans_message('blog_cms.media_size_bytes', ['size' => '0']);
        }

        if ($size < 1024 * 1024) {
            return trans_message('blog_cms.media_size_kilobytes', [
                'size' => number_format($size / 1024, 1, ',', ' '),
            ]);
        }

        return trans_message('blog_cms.media_size_megabytes', [
            'size' => number_format($size / (1024 * 1024), 1, ',', ' '),
        ]);
    }
}
