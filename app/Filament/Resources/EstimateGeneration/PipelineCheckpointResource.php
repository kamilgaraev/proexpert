<?php

declare(strict_types=1);

namespace App\Filament\Resources\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPipelineCheckpoint;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Operations\AdminSessionOperation;
use App\BusinessModules\Addons\EstimateGeneration\Operations\AdminSessionOperationCommand;
use App\BusinessModules\Addons\EstimateGeneration\Operations\AdminSessionOperationResult;
use App\BusinessModules\Addons\EstimateGeneration\Operations\OperateEstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointStatus;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\Filament\Resources\EstimateGeneration\PipelineCheckpointResource\Pages;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\SystemAdminAccess;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PipelineCheckpointResource extends Resource
{
    protected static ?string $model = EstimateGenerationPipelineCheckpoint::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return NavigationGroups::aiEstimator();
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('estimate_generation.checkpoints.navigation');
    }

    public static function getModelLabel(): string
    {
        return trans_message('estimate_generation.checkpoints.model');
    }

    public static function getPluralModelLabel(): string
    {
        return trans_message('estimate_generation.checkpoints.plural_model');
    }

    public static function hasTitleCaseModelLabel(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->select(self::safeColumns())
            ->with('session:id,organization_id,project_id,status,state_version');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\TextColumn::make('session_id')->label(trans_message('estimate_generation.usage.session'))->sortable(),
                Tables\Columns\TextColumn::make('stage')->label(trans_message('estimate_generation.sessions.stage'))->badge(),
                Tables\Columns\TextColumn::make('status')->label(trans_message('estimate_generation.sessions.status'))->badge(),
                Tables\Columns\TextColumn::make('attempt_count')->label(trans_message('estimate_generation.sessions.attempt'))->numeric(),
                Tables\Columns\TextColumn::make('artifact_bytes')->label(trans_message('estimate_generation.checkpoints.artifact_bytes'))->numeric(),
                Tables\Columns\TextColumn::make('lease_expires_at')->label(trans_message('estimate_generation.checkpoints.lease_expires_at'))->dateTime(),
                Tables\Columns\TextColumn::make('started_at')->label(trans_message('estimate_generation.sessions.started_at'))->dateTime(),
                Tables\Columns\TextColumn::make('completed_at')->label(trans_message('estimate_generation.sessions.completed_at'))->dateTime(),
                Tables\Columns\TextColumn::make('last_error_code')->label(trans_message('estimate_generation.sessions.error_code')),
            ])
            ->filters([
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
                Tables\Filters\SelectFilter::make('status')
                    ->label(trans_message('estimate_generation.sessions.status'))
                    ->options(array_column(array_map(static fn (CheckpointStatus $status): array => [
                        trans_message('estimate_generation.checkpoints.statuses.'.$status->value), $status->value,
                    ], CheckpointStatus::cases()), 0, 1)),
            ])
            ->recordActions([
                self::operationAction(AdminSessionOperation::Retry),
                self::operationAction(AdminSessionOperation::Cancel),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPipelineCheckpoints::route('/')];
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
        return $record instanceof EstimateGenerationPipelineCheckpoint && self::canViewAny();
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

    private static function operationAction(AdminSessionOperation $operation): Action
    {
        return Action::make($operation->value)
            ->label(trans_message('estimate_generation.sessions.actions.'.$operation->value))
            ->requiresConfirmation()
            ->visible(static fn (EstimateGenerationPipelineCheckpoint $record): bool => self::actionMatchesCheckpoint($operation, $record)
                && SystemAdminAccess::can(FilamentPermission::ESTIMATE_GENERATION_OPERATE))
            ->action(static function (EstimateGenerationPipelineCheckpoint $record) use ($operation): void {
                $actor = SystemAdminAccess::user();
                $session = $record->session;
                $result = $actor === null || ! $session instanceof EstimateGenerationSession
                    ? AdminSessionOperationResult::failure('estimate_generation.admin_operation_forbidden')
                    : app(OperateEstimateGenerationSession::class)->handle(new AdminSessionOperationCommand(
                        (int) $actor->getKey(),
                        (int) $record->session_id,
                        (int) $record->organization_id,
                        (int) $record->project_id,
                        (int) $session->state_version,
                        $operation,
                        (string) Str::ulid(),
                    ));
                $notification = Notification::make()->title(trans_message($result->messageKey));
                ($result->successful ? $notification->success() : $notification->danger())->send();
            });
    }

    private static function actionMatchesCheckpoint(
        AdminSessionOperation $operation,
        EstimateGenerationPipelineCheckpoint $record,
    ): bool {
        $status = $record->status instanceof \BackedEnum ? $record->status->value : (string) $record->status;

        return match ($operation) {
            AdminSessionOperation::Retry => $status === 'failed',
            AdminSessionOperation::Cancel => $status === 'running',
            AdminSessionOperation::Archive => false,
        };
    }

    /** @return list<string> */
    private static function safeColumns(): array
    {
        return [
            'id', 'session_id', 'organization_id', 'project_id', 'generation_attempt_id',
            'stage', 'status', 'attempt_count', 'artifact_bytes', 'lease_expires_at',
            'started_at', 'completed_at', 'failed_at', 'invalidated_at',
            'invalidation_reason', 'last_error_code', 'created_at', 'updated_at',
        ];
    }
}
