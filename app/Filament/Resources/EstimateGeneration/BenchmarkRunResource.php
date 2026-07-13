<?php

declare(strict_types=1);

namespace App\Filament\Resources\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationBenchmarkRun;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationTrainingDataset;
use App\BusinessModules\Addons\EstimateGeneration\Operations\AdminBenchmarkDispatchCommand;
use App\BusinessModules\Addons\EstimateGeneration\Operations\AdminBenchmarkDispatchService;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsService;
use App\Filament\Resources\EstimateGeneration\BenchmarkRunResource\Pages;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\SystemAdminAccess;
use App\Filament\Support\TableEmptyState;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class BenchmarkRunResource extends Resource
{
    protected static ?string $model = EstimateGenerationBenchmarkRun::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-beaker';

    protected static ?int $navigationSort = 11;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return NavigationGroups::aiEstimator();
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('estimate_generation.benchmark_navigation_label');
    }

    public static function getModelLabel(): string
    {
        return trans_message('estimate_generation.benchmark_model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans_message('estimate_generation.benchmark_plural_model_label');
    }

    public static function hasTitleCaseModelLabel(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return TableEmptyState::for($table, 'estimate_generation_benchmark_runs', 'heroicon-o-beaker')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->select(self::safeColumns())
                ->with('dataset:id,organization_id,dataset_key,version,dataset_type,title'))
            ->columns([
                Tables\Columns\TextColumn::make('uuid')->label(trans_message('estimate_generation.benchmark_uuid'))->copyable(),
                Tables\Columns\TextColumn::make('dataset.dataset_type')->label(trans_message('estimate_generation.training_dataset_type'))->badge(),
                Tables\Columns\TextColumn::make('dataset_version')->label(trans_message('estimate_generation.training_version'))->sortable(),
                Tables\Columns\TextColumn::make('pipeline_version')->label(trans_message('estimate_generation.benchmark_pipeline_version'))->sortable(),
                Tables\Columns\TextColumn::make('model_versions')->label(trans_message('estimate_generation.benchmark_model_versions'))->formatStateUsing(static fn (mixed $state): string => self::modelSummary($state)),
                Tables\Columns\TextColumn::make('normative_version')->label(trans_message('estimate_generation.benchmark_normative_version')),
                Tables\Columns\TextColumn::make('price_version')->label(trans_message('estimate_generation.benchmark_price_version')),
                Tables\Columns\TextColumn::make('cost_amount')->label(trans_message('estimate_generation.benchmark_cost'))->formatStateUsing(static fn (mixed $state, EstimateGenerationBenchmarkRun $record): string => (string) $state.' '.(string) $record->currency),
                Tables\Columns\TextColumn::make('duration_ms')->label(trans_message('estimate_generation.benchmark_duration'))->formatStateUsing(static fn (?int $state): string => $state === null ? '—' : number_format($state / 1000, 1, ',', ' ').' с'),
                Tables\Columns\TextColumn::make('status')->label(trans_message('estimate_generation.benchmark_status'))->badge()->sortable(),
                Tables\Columns\TextColumn::make('started_at')->label(trans_message('estimate_generation.benchmark_started_at'))->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    EstimateGenerationBenchmarkRun::STATUS_RUNNING => trans_message('estimate_generation.benchmark_status_running'),
                    EstimateGenerationBenchmarkRun::STATUS_COMPLETED => trans_message('estimate_generation.benchmark_status_completed'),
                    EstimateGenerationBenchmarkRun::STATUS_FAILED => trans_message('estimate_generation.benchmark_status_failed'),
                ]),
                Tables\Filters\SelectFilter::make('training_dataset_id')
                    ->label(trans_message('estimate_generation.benchmark_dataset'))
                    ->options(fn (): array => self::datasetOptions()),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('started_at', 'desc')
            ->paginationPageOptions([25, 50, 100]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(trans_message('estimate_generation.benchmark_summary'))
                ->schema([
                    \Filament\Infolists\Components\TextEntry::make('uuid')->label(trans_message('estimate_generation.benchmark_uuid')),
                    \Filament\Infolists\Components\TextEntry::make('dataset.title')->label(trans_message('estimate_generation.benchmark_dataset')),
                    \Filament\Infolists\Components\TextEntry::make('pipeline_version')->label(trans_message('estimate_generation.benchmark_pipeline_version')),
                    \Filament\Infolists\Components\TextEntry::make('normative_version')->label(trans_message('estimate_generation.benchmark_normative_version')),
                    \Filament\Infolists\Components\TextEntry::make('price_version')->label(trans_message('estimate_generation.benchmark_price_version')),
                    \Filament\Infolists\Components\TextEntry::make('status')->label(trans_message('estimate_generation.benchmark_status'))->badge(),
                    \Filament\Infolists\Components\TextEntry::make('failure_code')->label(trans_message('estimate_generation.benchmark_failure_code'))->placeholder('—'),
                ])->columns(2),
            Section::make(trans_message('estimate_generation.benchmark_metrics'))
                ->schema([
                    \Filament\Infolists\Components\KeyValueEntry::make('metrics')
                        ->label(trans_message('estimate_generation.benchmark_metrics')),
                ]),
            Section::make(trans_message('estimate_generation.benchmark_metric_deltas'))
                ->schema([
                    \Filament\Infolists\Components\KeyValueEntry::make('metric_deltas')
                        ->label(trans_message('estimate_generation.benchmark_metric_deltas')),
                ]),
            Section::make(trans_message('estimate_generation.benchmark_case_failures'))
                ->schema([
                    \Filament\Infolists\Components\RepeatableEntry::make('case_failures')
                        ->label(trans_message('estimate_generation.benchmark_case_failures'))
                        ->schema([
                            \Filament\Infolists\Components\TextEntry::make('case_id')->label(trans_message('estimate_generation.benchmark_case_id')),
                            \Filament\Infolists\Components\TextEntry::make('status')->label(trans_message('estimate_generation.benchmark_status')),
                            \Filament\Infolists\Components\TextEntry::make('failure_code')->label(trans_message('estimate_generation.benchmark_failure_code')),
                        ])->columns(3),
                ]),
        ]);
    }

    public static function runBenchmarkAction(): Action
    {
        return Action::make('run_benchmark')
            ->label(trans_message('estimate_generation.benchmark_run_action'))
            ->icon('heroicon-o-play')
            ->visible(fn (): bool => SystemAdminAccess::can(FilamentPermission::ESTIMATE_GENERATION_BENCHMARKS))
            ->requiresConfirmation()
            ->schema([
                Select::make('dataset_id')->label(trans_message('estimate_generation.benchmark_dataset'))->options(fn (): array => self::datasetOptions())->searchable()->required(),
                TextInput::make('pipeline_version')->label(trans_message('estimate_generation.benchmark_pipeline_version'))->required()->maxLength(96),
                Select::make('adapter_id')->label(trans_message('estimate_generation.benchmark_adapter'))->options([
                    'production-replay' => trans_message('estimate_generation.benchmark_adapter_production_replay'),
                    'current-baseline' => trans_message('estimate_generation.benchmark_adapter_current_baseline'),
                ])->default('production-replay')->required(),
                TextInput::make('prompt_version')->label(trans_message('estimate_generation.benchmark_prompt_version'))->default('recorded-ports:v3')->required()->maxLength(96),
                TextInput::make('normative_version')->label(trans_message('estimate_generation.benchmark_normative_version'))->required()->maxLength(96),
                TextInput::make('price_version')->label(trans_message('estimate_generation.benchmark_price_version'))->required()->maxLength(96),
                Toggle::make('confirmed_acceptance')->label(trans_message('estimate_generation.benchmark_acceptance_confirmation'))->default(false),
                Hidden::make('idempotency_key')->default(fn (): string => (string) Str::ulid())->required(),
            ])
            ->action(function (array $data): void {
                $actor = SystemAdminAccess::user();
                $dataset = EstimateGenerationTrainingDataset::query()->find((int) ($data['dataset_id'] ?? 0));
                if ($actor === null || ! $dataset instanceof EstimateGenerationTrainingDataset || $dataset->organization_id === null) {
                    return;
                }
                $settings = app(EstimateGenerationSettingsService::class)->snapshotForNewWork((int) $dataset->organization_id);
                $snapshot = $settings['snapshot'];
                app(AdminBenchmarkDispatchService::class)->handle(new AdminBenchmarkDispatchCommand(
                    actorId: (int) $actor->id,
                    datasetId: (int) $dataset->id,
                    organizationId: (int) $dataset->organization_id,
                    confirmedAcceptance: (bool) ($data['confirmed_acceptance'] ?? false),
                    idempotencyKey: (string) $data['idempotency_key'],
                    manifest: [
                        'pipeline_version' => (string) $data['pipeline_version'],
                        'adapter_id' => (string) $data['adapter_id'],
                        'prompt_version' => (string) $data['prompt_version'],
                        'model_versions' => $snapshot['models'],
                        'normative_version' => (string) $data['normative_version'],
                        'price_version' => (string) $data['price_version'],
                        'currency' => (string) $snapshot['budgets']['currency'],
                    ],
                ));
                Notification::make()->success()->title(trans_message('estimate_generation.benchmark_queued'))->send();
            });
    }

    /** @return list<string> */
    public static function safeColumns(): array
    {
        return [
            'id', 'uuid', 'organization_id', 'training_dataset_id', 'dataset_version',
            'pipeline_version', 'model_versions', 'normative_version', 'price_version',
            'metrics', 'duration_ms', 'cost_amount', 'currency', 'status', 'failure_code',
            'started_at', 'completed_at',
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBenchmarkRuns::route('/'),
            'view' => Pages\ViewBenchmarkRun::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return SystemAdminAccess::can(FilamentPermission::ESTIMATE_GENERATION_BENCHMARKS);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canViewAny();
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof EstimateGenerationBenchmarkRun && self::canViewAny();
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

    /** @return array<int, string> */
    private static function datasetOptions(): array
    {
        return EstimateGenerationTrainingDataset::query()
            ->where('status', EstimateGenerationTrainingDataset::STATUS_APPROVED)
            ->orderByDesc('version')
            ->limit(500)
            ->get(['id', 'title', 'version', 'dataset_type'])
            ->mapWithKeys(static fn (EstimateGenerationTrainingDataset $dataset): array => [
                (int) $dataset->id => sprintf('%s · v%d · %s', (string) $dataset->title, (int) $dataset->version, (string) $dataset->dataset_type),
            ])->all();
    }

    private static function modelSummary(mixed $value): string
    {
        if (! is_array($value)) {
            return '—';
        }
        $models = array_filter($value, 'is_string');

        return implode(', ', array_slice($models, 0, 5));
    }
}
