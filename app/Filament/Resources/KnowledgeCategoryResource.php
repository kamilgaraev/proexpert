<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\BusinessModules\Features\KnowledgeHub\Models\KnowledgeCategory;
use App\Filament\Resources\KnowledgeCategoryResource\Pages;
use App\Filament\Support\Concerns\AuthorizesSystemAdminResource;
use App\Filament\Support\Concerns\HasDestructiveActionGuardrails;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\TableEmptyState;
use App\Policies\SystemAdmin\KnowledgeCategoryPolicy;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class KnowledgeCategoryResource extends Resource
{
    use AuthorizesSystemAdminResource;
    use HasDestructiveActionGuardrails;

    protected static ?string $model = KnowledgeCategory::class;

    protected static string $systemAdminPolicy = KnowledgeCategoryPolicy::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-folder-open';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return NavigationGroups::knowledgeHub();
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('knowledge_hub.filament.categories_label');
    }

    public static function getModelLabel(): string
    {
        return trans_message('knowledge_hub.filament.category_label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans_message('knowledge_hub.filament.categories_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(trans_message('knowledge_hub.filament.category_label'))
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->label(trans_message('knowledge_hub.filament.field_title'))
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('slug')
                        ->label(trans_message('knowledge_hub.filament.field_slug'))
                        ->required()
                        ->maxLength(255)
                        ->unique(KnowledgeCategory::class, 'slug', ignoreRecord: true),
                    Forms\Components\Textarea::make('description')
                        ->label(trans_message('knowledge_hub.filament.field_description'))
                        ->rows(3)
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('icon')
                        ->label(trans_message('knowledge_hub.filament.field_icon'))
                        ->maxLength(120),
                    Forms\Components\TextInput::make('color')
                        ->label(trans_message('knowledge_hub.filament.field_color'))
                        ->maxLength(40),
                    Forms\Components\TextInput::make('sort_order')
                        ->label(trans_message('knowledge_hub.filament.field_sort_order'))
                        ->numeric()
                        ->default(0),
                    Forms\Components\Toggle::make('is_active')
                        ->label(trans_message('knowledge_hub.filament.field_is_active'))
                        ->default(true),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return TableEmptyState::for($table, 'knowledge_categories', 'heroicon-o-folder-open')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label(trans_message('knowledge_hub.filament.field_title'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('slug')
                    ->label(trans_message('knowledge_hub.filament.field_slug'))
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label(trans_message('knowledge_hub.filament.field_is_active'))
                    ->boolean(),
                Tables\Columns\TextColumn::make('sort_order')
                    ->label(trans_message('knowledge_hub.filament.field_sort_order'))
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->actions([
                EditAction::make(),
                self::guardedDeleteAction('knowledge_category'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKnowledgeCategories::route('/'),
            'create' => Pages\CreateKnowledgeCategory::route('/create'),
            'edit' => Pages\EditKnowledgeCategory::route('/{record}/edit'),
        ];
    }
}
