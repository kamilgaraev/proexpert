<?php

declare(strict_types=1);

namespace App\Filament\Resources\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationFailure;
use App\BusinessModules\Addons\EstimateGeneration\Operations\AdminFailureResolutionCommand;
use App\BusinessModules\Addons\EstimateGeneration\Operations\AdminFailureResolutionResult;
use App\BusinessModules\Addons\EstimateGeneration\Operations\ResolveEstimateGenerationFailure;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\Filament\Resources\EstimateGeneration\FailureResource\Pages;
use App\Filament\Support\EstimateGeneration\FailureDiagnosticsPresenter;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\SystemAdminAccess;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Str;

class FailureResource extends Resource
{
    protected static ?string $model = EstimateGenerationFailure::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?int $navigationSort = 4;

    private const DIAGNOSTIC_KEYS = [
        'provider_code', 'http_class', 'http_code', 'status', 'safe_code',
        'retry_after_seconds', 'attempt', 'validation_code', 'storage_code',
        'claim_status', 'lineage_code', 'failure_fingerprint',
    ];

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return NavigationGroups::aiEstimator();
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('estimate_generation.failures.navigation');
    }

    public static function getModelLabel(): string
    {
        return trans_message('estimate_generation.failures.model');
    }

    public static function getPluralModelLabel(): string
    {
        return trans_message('estimate_generation.failures.plural_model');
    }

    public static function hasTitleCaseModelLabel(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->select(self::safeColumns());
        foreach (self::DIAGNOSTIC_KEYS as $key) {
            $query->addSelect(new Expression("safe_context->>'{$key}' as diagnostic_{$key}"));
        }

        return $query->with('session:id,organization_id,project_id,status');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(trans_message('estimate_generation.failures.summary'))->schema([
                TextEntry::make('session_id')->label(trans_message('estimate_generation.usage.session')),
                TextEntry::make('stage')->label(trans_message('estimate_generation.sessions.stage'))->badge(),
                TextEntry::make('operation')->label(trans_message('estimate_generation.sessions.operation')),
                TextEntry::make('provider')->label(trans_message('estimate_generation.dashboard.provider')),
                TextEntry::make('model')->label(trans_message('estimate_generation.dashboard.model')),
                TextEntry::make('category')->label(trans_message('estimate_generation.sessions.category'))->badge(),
                TextEntry::make('code')->label(trans_message('estimate_generation.sessions.error_code')),
                TextEntry::make('occurrence_count')->label(trans_message('estimate_generation.sessions.occurrences')),
                TextEntry::make('last_seen_at')->label(trans_message('estimate_generation.sessions.last_seen_at'))->dateTime(),
                TextEntry::make('resolved_at')->label(trans_message('estimate_generation.sessions.resolved_at'))->dateTime(),
                TextEntry::make('diagnostics')
                    ->label(trans_message('estimate_generation.failures.diagnostics'))
                    ->state(static fn (EstimateGenerationFailure $record): string => implode(' · ', array_values(
                        FailureDiagnosticsPresenter::present(self::diagnosticState($record)),
                    )))
                    ->placeholder(trans_message('estimate_generation.dashboard.unavailable')),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_seen_at', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->columns([
                Tables\Columns\TextColumn::make('session_id')->label(trans_message('estimate_generation.usage.session'))->sortable(),
                Tables\Columns\TextColumn::make('stage')->label(trans_message('estimate_generation.sessions.stage'))->badge(),
                Tables\Columns\TextColumn::make('operation')->label(trans_message('estimate_generation.sessions.operation')),
                Tables\Columns\TextColumn::make('provider')->label(trans_message('estimate_generation.dashboard.provider')),
                Tables\Columns\TextColumn::make('model')->label(trans_message('estimate_generation.dashboard.model'))->limit(50),
                Tables\Columns\TextColumn::make('category')->label(trans_message('estimate_generation.sessions.category'))->badge(),
                Tables\Columns\TextColumn::make('code')->label(trans_message('estimate_generation.sessions.error_code')),
                Tables\Columns\TextColumn::make('occurrence_count')->label(trans_message('estimate_generation.sessions.occurrences'))->numeric(),
                Tables\Columns\TextColumn::make('last_seen_at')->label(trans_message('estimate_generation.sessions.last_seen_at'))->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('resolved_at')->label(trans_message('estimate_generation.sessions.resolved_at'))->dateTime(),
            ])
            ->filters([
                Tables\Filters\Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->label(trans_message('estimate_generation.dashboard.date_from')),
                        DatePicker::make('until')->label(trans_message('estimate_generation.dashboard.date_to')),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, static fn (Builder $query, mixed $date): Builder => $query->where('last_seen_at', '>=', $date))
                        ->when($data['until'] ?? null, static fn (Builder $query, mixed $date): Builder => $query->where('last_seen_at', '<', date('Y-m-d', strtotime((string) $date.' +1 day'))))),
                Tables\Filters\SelectFilter::make('organization_id')
                    ->label(trans_message('estimate_generation.sessions.organization'))
                    ->relationship('organization', 'name')
                    ->searchable()
                    ->preload(false),
                Tables\Filters\SelectFilter::make('stage')
                    ->label(trans_message('estimate_generation.sessions.stage'))
                    ->options(array_column(array_map(static fn (ProcessingStage $stage): array => [
                        trans_message('estimate_generation.dashboard.stages.'.$stage->value), $stage->value,
                    ], ProcessingStage::cases()), 0, 1)),
                Tables\Filters\SelectFilter::make('category')->label(trans_message('estimate_generation.sessions.category'))->options([
                    'recoverable' => trans_message('estimate_generation.failures.categories.recoverable'),
                    'user_action_required' => trans_message('estimate_generation.failures.categories.user_action_required'),
                    'terminal' => trans_message('estimate_generation.failures.categories.terminal'),
                ]),
                Tables\Filters\TernaryFilter::make('resolved_at')
                    ->label(trans_message('estimate_generation.sessions.resolved_at'))
                    ->queries(
                        true: static fn (Builder $query): Builder => $query->whereNotNull('resolved_at'),
                        false: static fn (Builder $query): Builder => $query->whereNull('resolved_at'),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                self::resolveAction(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFailures::route('/'),
            'view' => Pages\ViewFailure::route('/{record}'),
        ];
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
        return $record instanceof EstimateGenerationFailure && self::canViewAny();
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

    private static function resolveAction(): Action
    {
        return Action::make('mark_resolved')
            ->label(trans_message('estimate_generation.failures.mark_resolved'))
            ->requiresConfirmation()
            ->visible(static fn (EstimateGenerationFailure $record): bool => $record->resolved_at === null
                && SystemAdminAccess::can(FilamentPermission::ESTIMATE_GENERATION_OPERATE))
            ->action(static function (EstimateGenerationFailure $record): void {
                $actor = SystemAdminAccess::user();
                $result = $actor === null
                    ? AdminFailureResolutionResult::failure('estimate_generation.admin_operation_forbidden')
                    : app(ResolveEstimateGenerationFailure::class)->handle(new AdminFailureResolutionCommand(
                        (int) $actor->getKey(),
                        (string) $record->getKey(),
                        (int) $record->organization_id,
                        (int) $record->project_id,
                        (int) $record->session_id,
                        (int) $record->latest_occurrence_sequence,
                        (string) Str::ulid(),
                    ));
                $notification = Notification::make()->title(trans_message($result->messageKey));
                ($result->successful ? $notification->success() : $notification->danger())->send();
            });
    }

    /** @return list<string> */
    private static function safeColumns(): array
    {
        return [
            'id', 'organization_id', 'project_id', 'session_id', 'document_id', 'page_id',
            'unit_id', 'checkpoint_id', 'usage_attempt_id', 'stage', 'operation', 'provider',
            'model', 'category', 'code', 'attempt', 'occurrence_count', 'first_seen_at',
            'last_seen_at', 'resolved_at', 'resolution_code', 'latest_occurrence_sequence',
        ];
    }

    /** @return array<string, mixed> */
    private static function diagnosticState(EstimateGenerationFailure $record): array
    {
        return array_combine(
            self::DIAGNOSTIC_KEYS,
            array_map(static fn (string $key): mixed => $record->getAttribute('diagnostic_'.$key), self::DIAGNOSTIC_KEYS),
        );
    }
}
