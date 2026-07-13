<?php

declare(strict_types=1);

namespace App\Filament\Resources\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Operations\AdminSessionOperation;
use App\BusinessModules\Addons\EstimateGeneration\Operations\AdminSessionOperationCommand;
use App\BusinessModules\Addons\EstimateGeneration\Operations\OperateEstimateGenerationSession;
use App\Filament\Resources\EstimateGeneration\SessionResource\Pages;
use App\Filament\Resources\EstimateGeneration\SessionResource\RelationManagers;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\SystemAdminAccess;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SessionResource extends Resource
{
    protected static ?string $model = EstimateGenerationSession::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-command-line';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return NavigationGroups::aiEstimator();
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('estimate_generation.sessions.navigation');
    }

    public static function getModelLabel(): string
    {
        return trans_message('estimate_generation.sessions.model');
    }

    public static function getPluralModelLabel(): string
    {
        return trans_message('estimate_generation.sessions.plural_model');
    }

    public static function hasTitleCaseModelLabel(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->select(self::safeSessionColumns())
            ->with(['organization:id,name', 'project:id,name'])
            ->withCount(['documents', 'checkpoints', 'processingUnits', 'failures']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(trans_message('estimate_generation.sessions.summary'))
                ->schema([
                    TextEntry::make('id')->label(trans_message('estimate_generation.sessions.id')),
                    TextEntry::make('organization.name')->label(trans_message('estimate_generation.sessions.organization')),
                    TextEntry::make('project.name')->label(trans_message('estimate_generation.sessions.project')),
                    TextEntry::make('status')->label(trans_message('estimate_generation.sessions.status'))->formatStateUsing(self::formatEnum(...))->badge(),
                    TextEntry::make('processing_stage')->label(trans_message('estimate_generation.sessions.stage')),
                    TextEntry::make('processing_progress')->label(trans_message('estimate_generation.sessions.progress'))->suffix('%'),
                    TextEntry::make('state_version')->label(trans_message('estimate_generation.sessions.state_version')),
                    TextEntry::make('created_at')->label(trans_message('estimate_generation.sessions.created_at'))->dateTime(),
                    TextEntry::make('state_changed_at')->label(trans_message('estimate_generation.sessions.state_changed_at'))->dateTime(),
                    TextEntry::make('applied_estimate_id')
                        ->label(trans_message('estimate_generation.sessions.applied_estimate'))
                        ->placeholder(trans_message('estimate_generation.dashboard.unavailable'))
                        ->url(static fn (EstimateGenerationSession $record): ?string => $record->applied_estimate_id === null
                            ? null
                            : rtrim((string) config('app.frontend_url'), '/')."/projects/{$record->project_id}/estimates/{$record->applied_estimate_id}")
                        ->openUrlInNewTab(),
                ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->columns([
                Tables\Columns\TextColumn::make('id')->label(trans_message('estimate_generation.sessions.id'))->sortable(),
                Tables\Columns\TextColumn::make('organization.name')->label(trans_message('estimate_generation.sessions.organization')),
                Tables\Columns\TextColumn::make('project.name')->label(trans_message('estimate_generation.sessions.project')),
                Tables\Columns\TextColumn::make('status')->label(trans_message('estimate_generation.sessions.status'))->formatStateUsing(self::formatEnum(...))->badge(),
                Tables\Columns\TextColumn::make('processing_stage')->label(trans_message('estimate_generation.sessions.stage'))->badge(),
                Tables\Columns\TextColumn::make('processing_progress')->label(trans_message('estimate_generation.sessions.progress'))->suffix('%'),
                Tables\Columns\TextColumn::make('documents_count')->label(trans_message('estimate_generation.sessions.documents')),
                Tables\Columns\TextColumn::make('failures_count')->label(trans_message('estimate_generation.sessions.failures')),
                Tables\Columns\TextColumn::make('created_at')->label(trans_message('estimate_generation.sessions.created_at'))->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label(trans_message('estimate_generation.sessions.status'))->options(self::statusOptions()),
                Tables\Filters\SelectFilter::make('processing_stage')
                    ->label(trans_message('estimate_generation.sessions.stage'))
                    ->options(array_column(array_map(static fn (\App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage $stage): array => [
                        trans_message('estimate_generation.dashboard.stages.'.$stage->value), $stage->value,
                    ], \App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage::cases()), 0, 1)),
                Tables\Filters\SelectFilter::make('organization_id')->label(trans_message('estimate_generation.sessions.organization'))->relationship('organization', 'name')->searchable()->preload(false),
                Tables\Filters\SelectFilter::make('project_id')->label(trans_message('estimate_generation.sessions.project'))->relationship('project', 'name')->searchable()->preload(false),
            ])
            ->recordActions([
                ViewAction::make(),
                self::operationAction(AdminSessionOperation::Retry),
                self::operationAction(AdminSessionOperation::Cancel),
                self::operationAction(AdminSessionOperation::Archive),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\DocumentsRelationManager::class,
            RelationManagers\CheckpointsRelationManager::class,
            RelationManagers\ProcessingUnitsRelationManager::class,
            RelationManagers\UsageRelationManager::class,
            RelationManagers\FailuresRelationManager::class,
            RelationManagers\AuditEventsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSessions::route('/'),
            'view' => Pages\ViewSession::route('/{record}'),
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
        return $record instanceof EstimateGenerationSession && self::canViewAny();
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
            ->visible(static fn (): bool => SystemAdminAccess::can(FilamentPermission::ESTIMATE_GENERATION_OPERATE))
            ->action(static function (EstimateGenerationSession $record) use ($operation): void {
                $actor = SystemAdminAccess::user();
                $result = $actor === null
                    ? \App\BusinessModules\Addons\EstimateGeneration\Operations\AdminSessionOperationResult::failure('estimate_generation.admin_operation_forbidden')
                    : app(OperateEstimateGenerationSession::class)->handle(new AdminSessionOperationCommand(
                        (int) $actor->getKey(),
                        (int) $record->getKey(),
                        (int) $record->organization_id,
                        (int) $record->project_id,
                        (int) $record->state_version,
                        $operation,
                        (string) Str::ulid(),
                    ));

                $notification = Notification::make()->title(trans_message($result->messageKey));
                ($result->successful ? $notification->success() : $notification->danger())->send();
            });
    }

    /** @return list<string> */
    private static function safeSessionColumns(): array
    {
        return [
            'id', 'organization_id', 'project_id', 'user_id', 'status', 'processing_stage',
            'processing_progress', 'applied_estimate_id', 'applied_at', 'state_version',
            'state_changed_at', 'failure_code', 'resume_status', 'created_at', 'updated_at',
        ];
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return array_column(array_map(static fn (EstimateGenerationStatus $status): array => [
            trans_message('estimate_generation.dashboard.statuses.'.$status->value), $status->value,
        ], EstimateGenerationStatus::cases()), 0, 1);
    }

    private static function formatEnum(mixed $value): string
    {
        $value = $value instanceof \BackedEnum ? $value->value : (string) $value;

        return trans_message('estimate_generation.dashboard.statuses.'.$value);
    }
}
