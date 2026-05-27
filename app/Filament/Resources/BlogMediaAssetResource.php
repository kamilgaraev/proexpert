<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\BlogMediaAssetResource\Pages;
use App\Filament\Support\Concerns\AuthorizesSystemAdminResource;
use App\Filament\Support\Concerns\HasDestructiveActionGuardrails;
use App\Filament\Support\NavigationGroups;
use App\Models\Blog\BlogMediaAsset;
use App\Policies\SystemAdmin\BlogMediaAssetPolicy;
use App\Services\Blog\BlogMediaService;
use App\Models\SystemAdmin;
use Filament\Actions\Action;
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

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-photo';

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return NavigationGroups::blog();
    }

    public static function getNavigationLabel(): string
    {
        return 'Медиатека';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Файл')
                ->schema([
                    Forms\Components\FileUpload::make('upload_file')
                        ->label('Файл')
                        ->acceptedFileTypes(BlogMediaService::allowedMimeTypes())
                        ->maxSize(BlogMediaService::maxUploadSizeKilobytes())
                        ->imageEditor()
                        ->storeFiles(false)
                        ->required(fn (string $operation): bool => $operation === 'create'),
                    Forms\Components\TextInput::make('alt_text')->label('Alt')->required(),
                    Forms\Components\TextInput::make('caption')->label('Подпись'),
                    Forms\Components\TextInput::make('focal_point.x')->label('Focal X')->numeric()->minValue(0)->maxValue(1),
                    Forms\Components\TextInput::make('focal_point.y')->label('Focal Y')->numeric()->minValue(0)->maxValue(1),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->where('blog_context', 'marketing'))
            ->columns([
                Tables\Columns\ImageColumn::make('public_url')->label('Превью'),
                Tables\Columns\TextColumn::make('filename')->label('Файл')->searchable(),
                Tables\Columns\TextColumn::make('mime_type')->label('MIME'),
                Tables\Columns\TextColumn::make('file_size')->label('Размер'),
                Tables\Columns\TextColumn::make('usage_metadata.count')->label('Использований')->default(0),
                Tables\Columns\TextColumn::make('updated_at')->label('Обновлен')->since(),
            ])
            ->actions([
                EditAction::make(),
                Action::make('safe_replace')
                    ->label(trans_message('blog_cms.media_replace_action'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->schema([
                        Forms\Components\FileUpload::make('replacement_file')
                            ->label('Файл')
                            ->acceptedFileTypes(BlogMediaService::allowedMimeTypes())
                            ->maxSize(BlogMediaService::maxUploadSizeKilobytes())
                            ->imageEditor()
                            ->storeFiles(false)
                            ->required(),
                        Forms\Components\TextInput::make('alt_text')
                            ->label('Alt')
                            ->required(),
                        Forms\Components\TextInput::make('caption')
                            ->label('Подпись'),
                    ])
                    ->visible(fn (): bool => Auth::guard('system_admin')->user()?->hasSystemPermission('system_admin.blog.media.manage') ?? false)
                    ->action(function (array $data, BlogMediaAsset $record): void {
                        /** @var SystemAdmin $systemAdmin */
                        $systemAdmin = Auth::guard('system_admin')->user();
                        $file = $data['replacement_file'] ?? null;

                        if (!$file instanceof TemporaryUploadedFile) {
                            return;
                        }

                        app(BlogMediaService::class)->replaceWithUploadedFile($record, $file, $systemAdmin, [
                            'alt_text' => $data['alt_text'] ?? null,
                            'caption' => $data['caption'] ?? null,
                        ]);

                        Notification::make()
                            ->success()
                            ->title(trans_message('blog_cms.media_replace_done'))
                            ->send();
                    }),
                self::guardedDeleteAction('media_asset'),
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
}
