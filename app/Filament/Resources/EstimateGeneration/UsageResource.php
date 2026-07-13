<?php

declare(strict_types=1);

namespace App\Filament\Resources\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationAiUsage;
use App\Filament\Resources\EstimateGeneration\UsageResource\Pages;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\SystemAdminAccess;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UsageResource extends Resource
{
    protected static ?string $model = EstimateGenerationAiUsage::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return NavigationGroups::aiEstimator();
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('estimate_generation.usage.navigation');
    }

    public static function getModelLabel(): string
    {
        return trans_message('estimate_generation.usage.model');
    }

    public static function getPluralModelLabel(): string
    {
        return trans_message('estimate_generation.usage.plural_model');
    }

    public static function hasTitleCaseModelLabel(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->select(self::safeColumns())
            ->with('session:id,organization_id,project_id,status');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->columns([
                Tables\Columns\TextColumn::make('session_id')->label(trans_message('estimate_generation.usage.session'))->sortable(),
                Tables\Columns\TextColumn::make('provider')->label(trans_message('estimate_generation.dashboard.provider')),
                Tables\Columns\TextColumn::make('requested_model')->label(trans_message('estimate_generation.dashboard.model'))->limit(50),
                Tables\Columns\TextColumn::make('stage')->label(trans_message('estimate_generation.sessions.stage'))->badge(),
                Tables\Columns\TextColumn::make('input_tokens')->label(trans_message('estimate_generation.usage.input_tokens'))->numeric(),
                Tables\Columns\TextColumn::make('cached_input_tokens')->label(trans_message('estimate_generation.usage.cached_input_tokens'))->numeric(),
                Tables\Columns\TextColumn::make('output_tokens')->label(trans_message('estimate_generation.usage.output_tokens'))->numeric(),
                Tables\Columns\TextColumn::make('reasoning_tokens')->label(trans_message('estimate_generation.usage.reasoning_tokens'))->numeric(),
                Tables\Columns\TextColumn::make('image_count')->label(trans_message('estimate_generation.usage.images'))->numeric(),
                Tables\Columns\TextColumn::make('page_count')->label(trans_message('estimate_generation.usage.pages'))->numeric(),
                Tables\Columns\TextColumn::make('duration_ms')->label(trans_message('estimate_generation.sessions.duration_ms'))->numeric(),
                Tables\Columns\TextColumn::make('attempt_ordinal')->label(trans_message('estimate_generation.sessions.attempt'))->numeric(),
                Tables\Columns\TextColumn::make('status')->label(trans_message('estimate_generation.sessions.status'))->badge(),
                Tables\Columns\TextColumn::make('cost_amount')->label(trans_message('estimate_generation.dashboard.total_cost'))->numeric(8),
                Tables\Columns\TextColumn::make('currency')->label(trans_message('estimate_generation.sessions.currency')),
                Tables\Columns\TextColumn::make('created_at')->label(trans_message('estimate_generation.sessions.created_at'))->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->label(trans_message('estimate_generation.dashboard.date_from')),
                        DatePicker::make('until')->label(trans_message('estimate_generation.dashboard.date_to')),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, static fn (Builder $query, mixed $date): Builder => $query->where('created_at', '>=', $date))
                        ->when($data['until'] ?? null, static fn (Builder $query, mixed $date): Builder => $query->where('created_at', '<', date('Y-m-d', strtotime((string) $date.' +1 day'))))),
                Tables\Filters\SelectFilter::make('organization_id')
                    ->label(trans_message('estimate_generation.sessions.organization'))
                    ->relationship('organization', 'name')
                    ->searchable()
                    ->preload(false),
                Tables\Filters\Filter::make('requested_model')
                    ->schema([TextInput::make('value')->label(trans_message('estimate_generation.dashboard.model'))->maxLength(160)])
                    ->query(static fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        static fn (Builder $query, mixed $model): Builder => $query->where('requested_model', $model),
                    )),
                Tables\Filters\SelectFilter::make('stage')->label(trans_message('estimate_generation.sessions.stage'))->options(self::stageOptions()),
                Tables\Filters\SelectFilter::make('status')->label(trans_message('estimate_generation.sessions.status'))->options(self::statusOptions()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListUsage::route('/')];
    }

    public static function canViewAny(): bool
    {
        return SystemAdminAccess::can(FilamentPermission::ESTIMATE_GENERATION_MONITOR);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canViewAny();
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof EstimateGenerationAiUsage && self::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** @return list<string> */
    private static function safeColumns(): array
    {
        return [
            'attempt_id', 'organization_id', 'project_id', 'session_id', 'stage', 'operation',
            'attempt_ordinal', 'provider', 'requested_model', 'reported_model', 'usage_status',
            'status', 'input_tokens', 'cached_input_tokens', 'output_tokens', 'reasoning_tokens',
            'image_count', 'page_count', 'duration_ms', 'cost_amount', 'currency',
            'pricing_status', 'created_at',
        ];
    }

    /** @return array<string, string> */
    private static function stageOptions(): array
    {
        return [
            'understand_documents' => trans_message('estimate_generation.dashboard.stages.understand_documents'),
            'match_normatives' => trans_message('estimate_generation.dashboard.stages.match_normatives'),
        ];
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return array_combine(
            ['succeeded', 'http_failed', 'connection_failed', 'malformed_response'],
            array_map(static fn (string $status): string => trans_message('estimate_generation.usage.statuses.'.$status), [
                'succeeded', 'http_failed', 'connection_failed', 'malformed_response',
            ]),
        );
    }
}
