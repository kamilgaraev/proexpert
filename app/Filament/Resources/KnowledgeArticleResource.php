<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeArticleKind;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeArticleStatus;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeAudience;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use App\BusinessModules\Features\KnowledgeHub\Models\KnowledgeArticle;
use App\BusinessModules\Features\KnowledgeHub\Models\KnowledgeCategory;
use App\Filament\Resources\KnowledgeArticleResource\Pages;
use App\Filament\Support\Concerns\AuthorizesSystemAdminResource;
use App\Filament\Support\Concerns\HasDestructiveActionGuardrails;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\TableEmptyState;
use App\Policies\SystemAdmin\KnowledgeArticlePolicy;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class KnowledgeArticleResource extends Resource
{
    use AuthorizesSystemAdminResource;
    use HasDestructiveActionGuardrails;

    protected static ?string $model = KnowledgeArticle::class;

    protected static string $systemAdminPolicy = KnowledgeArticlePolicy::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-book-open';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return NavigationGroups::knowledgeHub();
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('knowledge_hub.filament.articles_label');
    }

    public static function getModelLabel(): string
    {
        return trans_message('knowledge_hub.filament.article_label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans_message('knowledge_hub.filament.articles_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(trans_message('knowledge_hub.filament.section_publication'))
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->label(trans_message('knowledge_hub.filament.field_title'))
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('slug')
                        ->label(trans_message('knowledge_hub.filament.field_slug'))
                        ->required()
                        ->maxLength(255)
                        ->unique(KnowledgeArticle::class, 'slug', ignoreRecord: true),
                    Forms\Components\Select::make('kind')
                        ->label(trans_message('knowledge_hub.filament.field_kind'))
                        ->options(KnowledgeArticleKind::options())
                        ->default(KnowledgeArticleKind::ARTICLE->value)
                        ->required(),
                    Forms\Components\Select::make('status')
                        ->label(trans_message('knowledge_hub.filament.field_status'))
                        ->options(KnowledgeArticleStatus::options())
                        ->default(KnowledgeArticleStatus::DRAFT->value)
                        ->required(),
                    Forms\Components\Select::make('category_id')
                        ->label(trans_message('knowledge_hub.filament.field_category'))
                        ->options(fn (): array => KnowledgeCategory::query()
                            ->active()
                            ->ordered()
                            ->pluck('title', 'id')
                            ->all())
                        ->searchable()
                        ->preload(),
                    Forms\Components\Select::make('parent_id')
                        ->label(trans_message('knowledge_hub.filament.field_parent'))
                        ->options(function (?KnowledgeArticle $record): array {
                            $query = KnowledgeArticle::query();

                            if ($record !== null && $record->exists) {
                                $pathPrefix = $record->path !== null && $record->path !== ''
                                    ? $record->path
                                    : (string) $record->id;

                                $query
                                    ->whereKeyNot($record->id)
                                    ->where(function (Builder $builder) use ($pathPrefix): void {
                                        $builder->whereNull('path')
                                            ->orWhere('path', 'not like', $pathPrefix.'.%');
                                    });
                            }

                            return $query
                                ->orderBy('title')
                                ->pluck('title', 'id')
                                ->all();
                        })
                        ->searchable()
                        ->preload(),
                    Forms\Components\TextInput::make('sort_order')
                        ->label(trans_message('knowledge_hub.filament.field_sort_order'))
                        ->numeric()
                        ->default(0),
                    Forms\Components\Toggle::make('is_featured')
                        ->label(trans_message('knowledge_hub.filament.field_is_featured')),
                    Forms\Components\Toggle::make('is_pinned')
                        ->label(trans_message('knowledge_hub.filament.field_is_pinned')),
                    Forms\Components\TextInput::make('help_priority')
                        ->label(trans_message('knowledge_hub.filament.field_help_priority'))
                        ->numeric()
                        ->minValue(1)
                        ->default(100),
                    Forms\Components\TextInput::make('reading_time')
                        ->label(trans_message('knowledge_hub.filament.field_reading_time'))
                        ->numeric()
                        ->minValue(1)
                        ->default(1),
                    Forms\Components\DateTimePicker::make('published_at')
                        ->label(trans_message('knowledge_hub.filament.field_published_at')),
                ])
                ->columns(2),
            Section::make(trans_message('knowledge_hub.filament.section_body'))
                ->schema([
                    Forms\Components\Textarea::make('excerpt')
                        ->label(trans_message('knowledge_hub.filament.field_excerpt'))
                        ->rows(3)
                        ->maxLength(600)
                        ->columnSpanFull(),
                    Forms\Components\RichEditor::make('content')
                        ->label(trans_message('knowledge_hub.filament.field_content'))
                        ->columnSpanFull(),
                    Forms\Components\TagsInput::make('tags')
                        ->label(trans_message('knowledge_hub.filament.field_tags'))
                        ->columnSpanFull(),
                ])
                ->columns(1),
            Section::make(trans_message('knowledge_hub.filament.section_targeting'))
                ->schema([
                    Forms\Components\Select::make('audiences')
                        ->label(trans_message('knowledge_hub.filament.field_audiences'))
                        ->options(KnowledgeAudience::options())
                        ->multiple()
                        ->preload(),
                    Forms\Components\Select::make('surfaces')
                        ->label(trans_message('knowledge_hub.filament.field_surfaces'))
                        ->options(KnowledgeSurface::options())
                        ->multiple()
                        ->preload(),
                    Forms\Components\TagsInput::make('module_slugs')
                        ->label(trans_message('knowledge_hub.filament.field_module_slugs'))
                        ->columnSpanFull(),
                    Forms\Components\TagsInput::make('permission_keys')
                        ->label(trans_message('knowledge_hub.filament.field_permission_keys'))
                        ->columnSpanFull(),
                    Forms\Components\TagsInput::make('context_keys')
                        ->label(trans_message('knowledge_hub.filament.field_context_keys'))
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make(trans_message('knowledge_hub.filament.section_changelog'))
                ->schema([
                    Forms\Components\TextInput::make('release_version')
                        ->label(trans_message('knowledge_hub.filament.field_release_version'))
                        ->maxLength(120),
                    Forms\Components\DatePicker::make('release_date')
                        ->label(trans_message('knowledge_hub.filament.field_release_date')),
                ])
                ->columns(2),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(trans_message('knowledge_hub.filament.article_label'))
                ->schema([
                    Infolists\Components\TextEntry::make('title')
                        ->label(trans_message('knowledge_hub.filament.field_title')),
                    Infolists\Components\TextEntry::make('slug')
                        ->label(trans_message('knowledge_hub.filament.field_slug')),
                    Infolists\Components\TextEntry::make('kind')
                        ->label(trans_message('knowledge_hub.filament.field_kind'))
                        ->formatStateUsing(fn (?KnowledgeArticleKind $state): string => $state !== null
                            ? trans_message('knowledge_hub.kinds.'.$state->value)
                            : ''),
                    Infolists\Components\TextEntry::make('status')
                        ->label(trans_message('knowledge_hub.filament.field_status'))
                        ->formatStateUsing(fn (?KnowledgeArticleStatus $state): string => $state !== null
                            ? trans_message('knowledge_hub.statuses.'.$state->value)
                            : ''),
                    Infolists\Components\TextEntry::make('category.title')
                        ->label(trans_message('knowledge_hub.filament.field_category')),
                    Infolists\Components\TextEntry::make('parent.title')
                        ->label(trans_message('knowledge_hub.filament.field_parent')),
                    Infolists\Components\TextEntry::make('excerpt')
                        ->label(trans_message('knowledge_hub.filament.field_excerpt'))
                        ->columnSpanFull(),
                    Infolists\Components\TextEntry::make('surfaces')
                        ->label(trans_message('knowledge_hub.filament.field_surfaces'))
                        ->badge(),
                    Infolists\Components\TextEntry::make('module_slugs')
                        ->label(trans_message('knowledge_hub.filament.field_module_slugs'))
                        ->badge(),
                    Infolists\Components\TextEntry::make('published_at')
                        ->label(trans_message('knowledge_hub.filament.field_published_at'))
                        ->dateTime('d.m.Y H:i'),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return TableEmptyState::for($table, 'knowledge_articles', 'heroicon-o-book-open')
            ->modifyQueryUsing(fn ($query) => $query->with('category'))
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label(trans_message('knowledge_hub.filament.field_title'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('kind')
                    ->label(trans_message('knowledge_hub.filament.field_kind'))
                    ->formatStateUsing(fn (?KnowledgeArticleKind $state): string => $state !== null
                        ? trans_message('knowledge_hub.kinds.'.$state->value)
                        : '')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(trans_message('knowledge_hub.filament.field_status'))
                    ->formatStateUsing(fn (?KnowledgeArticleStatus $state): string => $state !== null
                        ? trans_message('knowledge_hub.statuses.'.$state->value)
                        : '')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('category.title')
                    ->label(trans_message('knowledge_hub.filament.field_category'))
                    ->badge()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('parent.title')
                    ->label(trans_message('knowledge_hub.filament.field_parent'))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('surfaces')
                    ->label(trans_message('knowledge_hub.filament.field_surfaces'))
                    ->badge()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('module_slugs')
                    ->label(trans_message('knowledge_hub.filament.field_module_slugs'))
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('is_featured')
                    ->label(trans_message('knowledge_hub.filament.field_is_featured'))
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_pinned')
                    ->label(trans_message('knowledge_hub.filament.field_is_pinned'))
                    ->boolean(),
                Tables\Columns\TextColumn::make('published_at')
                    ->label(trans_message('knowledge_hub.filament.field_published_at'))
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('kind')
                    ->label(trans_message('knowledge_hub.filament.field_kind'))
                    ->options(KnowledgeArticleKind::options()),
                Tables\Filters\SelectFilter::make('status')
                    ->label(trans_message('knowledge_hub.filament.field_status'))
                    ->options(KnowledgeArticleStatus::options()),
                Tables\Filters\SelectFilter::make('category_id')
                    ->label(trans_message('knowledge_hub.filament.field_category'))
                    ->options(fn (): array => KnowledgeCategory::query()
                        ->ordered()
                        ->pluck('title', 'id')
                        ->all()),
                Tables\Filters\SelectFilter::make('surfaces')
                    ->label(trans_message('knowledge_hub.filament.field_surfaces'))
                    ->options(KnowledgeSurface::options())
                    ->query(fn (Builder $query, array $data): Builder => empty($data['value'])
                        ? $query
                        : $query->whereJsonContains('surfaces', $data['value'])),
            ])
            ->defaultSort('published_at', 'desc')
            ->actions([
                ViewAction::make(),
                EditAction::make(),
                self::guardedDeleteAction('knowledge_article'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKnowledgeArticles::route('/'),
            'create' => Pages\CreateKnowledgeArticle::route('/create'),
            'view' => Pages\ViewKnowledgeArticle::route('/{record}'),
            'edit' => Pages\EditKnowledgeArticle::route('/{record}/edit'),
        ];
    }
}
