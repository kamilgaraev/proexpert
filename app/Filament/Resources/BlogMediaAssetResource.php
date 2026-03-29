<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\BlogMediaAssetResource\Pages;
use App\Models\Blog\BlogMediaAsset;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class BlogMediaAssetResource extends Resource
{
    protected static ?string $model = BlogMediaAsset::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-photo';

    protected static string | \UnitEnum | null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 4;

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
                        ->imageEditor(false)
                        ->storeFiles(false)
                        ->required(fn (string $operation): bool => $operation === 'create'),
                    Forms\Components\TextInput::make('alt_text')->label('Alt'),
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
                Tables\Columns\TextColumn::make('updated_at')->label('Обновлен')->since(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
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
