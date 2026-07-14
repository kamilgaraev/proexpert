<?php

declare(strict_types=1);

namespace App\Filament\Resources\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationTrainingDataset;
use App\BusinessModules\Addons\EstimateGeneration\Operations\AdminTrainingDatasetActionCommand;
use App\BusinessModules\Addons\EstimateGeneration\Operations\AdminTrainingDatasetActionService;
use App\Filament\Resources\EstimateGeneration\TrainingDatasetResource\Pages;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\SystemAdminAccess;
use App\Filament\Support\TableEmptyState;
use App\Models\Organization;
use App\Models\Project;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TrainingDatasetResource extends Resource
{
    protected static ?string $model = EstimateGenerationTrainingDataset::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return NavigationGroups::aiEstimator();
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('estimate_generation.training_navigation_label');
    }

    public static function getModelLabel(): string
    {
        return trans_message('estimate_generation.training_model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans_message('estimate_generation.training_plural_model_label');
    }

    public static function hasTitleCaseModelLabel(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(trans_message('estimate_generation.training_section_main'))
                ->schema([
                    Forms\Components\Select::make('organization_id')
                        ->label(trans_message('estimate_generation.training_organization'))
                        ->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(static function (Set $set): void {
                            $set('project_id', null);
                        })
                        ->required(),
                    Forms\Components\Select::make('project_id')
                        ->label(trans_message('estimate_generation.training_project'))
                        ->options(fn (Get $get): array => self::projectOptions($get('organization_id')))
                        ->searchable()
                        ->preload()
                        ->disabled(fn (Get $get): bool => ! is_numeric($get('organization_id'))),
                    Forms\Components\TextInput::make('title')
                        ->label(trans_message('estimate_generation.training_title'))
                        ->required()
                        ->maxLength(255),
                    Forms\Components\Select::make('dataset_type')
                        ->label(trans_message('estimate_generation.training_dataset_type'))
                        ->options(self::datasetTypeOptions())
                        ->default(EstimateGenerationTrainingDataset::TYPE_DEVELOPMENT)
                        ->required(),
                    Forms\Components\Select::make('source_system')
                        ->label(trans_message('estimate_generation.training_source_system'))
                        ->options(self::sourceSystemOptions())
                        ->default('grandsmeta')
                        ->required(),
                    Forms\Components\TextInput::make('source_quality_score')
                        ->label(trans_message('estimate_generation.training_source_quality_score'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(1)
                        ->default(0.85)
                        ->required(),
                    Forms\Components\TextInput::make('region_name')
                        ->label(trans_message('estimate_generation.training_region_name'))
                        ->maxLength(255),
                    Forms\Components\TextInput::make('period_name')
                        ->label(trans_message('estimate_generation.training_period_name'))
                        ->maxLength(255),
                    Forms\Components\Textarea::make('notes')
                        ->label(trans_message('estimate_generation.training_notes'))
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make(trans_message('estimate_generation.training_section_files'))
                ->schema([
                    Forms\Components\FileUpload::make('reference_estimate_file')
                        ->label(trans_message('estimate_generation.training_reference_estimate_file'))
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-excel',
                            'application/vnd.ms-excel.sheet.macroEnabled.12',
                            'application/xml',
                            'application/octet-stream',
                            'text/xml',
                            'text/plain',
                            'text/csv',
                        ])
                        ->storeFiles(false)
                        ->required()
                        ->columnSpanFull(),
                    Forms\Components\FileUpload::make('project_documents')
                        ->label(trans_message('estimate_generation.training_project_documents'))
                        ->multiple()
                        ->storeFiles(false)
                        ->columnSpanFull(),
                    Forms\Components\FileUpload::make('drawings')
                        ->label(trans_message('estimate_generation.training_drawings'))
                        ->multiple()
                        ->storeFiles(false)
                        ->columnSpanFull(),
                    Forms\Components\FileUpload::make('scans')
                        ->label(trans_message('estimate_generation.training_scans'))
                        ->multiple()
                        ->storeFiles(false)
                        ->columnSpanFull(),
                    Forms\Components\FileUpload::make('statements')
                        ->label(trans_message('estimate_generation.training_statements'))
                        ->multiple()
                        ->storeFiles(false)
                        ->columnSpanFull(),
                    Forms\Components\Toggle::make('auto_process')
                        ->label(trans_message('estimate_generation.training_auto_process'))
                        ->visible(fn (Get $get): bool => self::canProcess()
                            && $get('dataset_type') === EstimateGenerationTrainingDataset::TYPE_DEVELOPMENT)
                        ->default(true),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(trans_message('estimate_generation.training_section_main'))
                ->schema([
                    \Filament\Infolists\Components\TextEntry::make('title')
                        ->label(trans_message('estimate_generation.training_title')),
                    \Filament\Infolists\Components\TextEntry::make('organization.name')
                        ->label(trans_message('estimate_generation.training_organization')),
                    \Filament\Infolists\Components\TextEntry::make('project.name')
                        ->label(trans_message('estimate_generation.training_project'))
                        ->placeholder(trans_message('widgets.common.empty_value')),
                    \Filament\Infolists\Components\TextEntry::make('source_system')
                        ->label(trans_message('estimate_generation.training_source_system'))
                        ->formatStateUsing(fn (?string $state): string => self::sourceSystemOptions()[$state ?? ''] ?? (string) $state),
                    \Filament\Infolists\Components\TextEntry::make('status')
                        ->label(trans_message('estimate_generation.training_status'))
                        ->formatStateUsing(fn (?string $state): string => self::statusLabel($state))
                        ->badge(),
                    \Filament\Infolists\Components\TextEntry::make('dataset_type')
                        ->label(trans_message('estimate_generation.training_dataset_type'))
                        ->formatStateUsing(fn (?string $state): string => self::datasetTypeOptions()[$state ?? ''] ?? (string) $state)
                        ->badge(),
                    \Filament\Infolists\Components\TextEntry::make('version')
                        ->label(trans_message('estimate_generation.training_version')),
                    \Filament\Infolists\Components\TextEntry::make('quality_status')
                        ->label(trans_message('estimate_generation.training_quality_status'))
                        ->formatStateUsing(fn (?string $state): string => self::qualityStatusLabel($state))
                        ->badge(),
                    \Filament\Infolists\Components\TextEntry::make('trusted_review_status')
                        ->label(trans_message('estimate_generation.training_trusted_review_status'))
                        ->formatStateUsing(fn (?string $state): string => self::trustedReviewStatusOptions()[$state ?? ''] ?? (string) $state)
                        ->badge(),
                ])
                ->columns(2),
            Section::make(trans_message('estimate_generation.training_section_stats'))
                ->schema([
                    \Filament\Infolists\Components\TextEntry::make('stats.uploaded_files')
                        ->label(trans_message('estimate_generation.training_uploaded_files')),
                    \Filament\Infolists\Components\TextEntry::make('stats.parsed_rows')
                        ->label(trans_message('estimate_generation.training_parsed_rows')),
                    \Filament\Infolists\Components\TextEntry::make('stats.accepted_rows')
                        ->label(trans_message('estimate_generation.training_accepted_rows')),
                    \Filament\Infolists\Components\TextEntry::make('stats.skipped_rows')
                        ->label(trans_message('estimate_generation.training_skipped_rows')),
                    \Filament\Infolists\Components\TextEntry::make('stats.learning_examples_created')
                        ->label(trans_message('estimate_generation.training_learning_examples_created')),
                    \Filament\Infolists\Components\TextEntry::make('error_message')
                        ->label(trans_message('estimate_generation.training_error_message'))
                        ->placeholder(trans_message('widgets.common.empty_value'))
                        ->columnSpanFull(),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return TableEmptyState::for($table, 'estimate_generation_training_datasets', 'heroicon-o-academic-cap')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['organization', 'project'])
                ->withCount([
                    'files',
                    'examples',
                    'examples as learning_examples_count' => static fn (Builder $query): Builder => $query->whereNotNull('learning_example_id'),
                ]))
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label(trans_message('estimate_generation.training_title'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('organization.name')
                    ->label(trans_message('estimate_generation.training_organization'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('source_system')
                    ->label(trans_message('estimate_generation.training_source_system'))
                    ->formatStateUsing(fn (?string $state): string => self::sourceSystemOptions()[$state ?? ''] ?? (string) $state)
                    ->badge(),
                Tables\Columns\TextColumn::make('dataset_type')
                    ->label(trans_message('estimate_generation.training_dataset_type'))
                    ->formatStateUsing(fn (?string $state): string => self::datasetTypeOptions()[$state ?? ''] ?? (string) $state)
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('version')
                    ->label(trans_message('estimate_generation.training_version'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(trans_message('estimate_generation.training_status'))
                    ->formatStateUsing(fn (?string $state): string => self::statusLabel($state))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('trusted_review_status')
                    ->label(trans_message('estimate_generation.training_trusted_review_status'))
                    ->formatStateUsing(fn (?string $state): string => self::trustedReviewStatusOptions()[$state ?? ''] ?? (string) $state)
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('files_count')
                    ->label(trans_message('estimate_generation.training_uploaded_files'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('examples_count')
                    ->label(trans_message('estimate_generation.training_examples_count'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('learning_examples_count')
                    ->label(trans_message('estimate_generation.training_learning_examples_count'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(trans_message('estimate_generation.training_updated_at'))
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(trans_message('estimate_generation.training_status'))
                    ->options(self::statusOptions()),
                Tables\Filters\SelectFilter::make('source_system')
                    ->label(trans_message('estimate_generation.training_source_system'))
                    ->options(self::sourceSystemOptions()),
                Tables\Filters\SelectFilter::make('dataset_type')
                    ->label(trans_message('estimate_generation.training_dataset_type'))
                    ->options(self::datasetTypeOptions()),
                Tables\Filters\SelectFilter::make('trusted_review_status')
                    ->label(trans_message('estimate_generation.training_trusted_review_status'))
                    ->options(self::trustedReviewStatusOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('process')
                    ->label(trans_message('estimate_generation.training_process_action'))
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    ->visible(fn (): bool => self::canProcess())
                    ->disabled(fn (EstimateGenerationTrainingDataset $record): bool => $record->status !== EstimateGenerationTrainingDataset::STATUS_DRAFT)
                    ->action(fn (EstimateGenerationTrainingDataset $record): mixed => self::runAction($record, 'process')),
                Action::make('submit_review')
                    ->label(trans_message('estimate_generation.training_submit_review_action'))
                    ->visible(fn (EstimateGenerationTrainingDataset $record): bool => $record->dataset_type === EstimateGenerationTrainingDataset::TYPE_DEVELOPMENT
                        && $record->status === EstimateGenerationTrainingDataset::STATUS_REVIEW_REQUIRED
                        && ($record->trusted_review_status ?? EstimateGenerationTrainingDataset::TRUSTED_REVIEW_DRAFT) === EstimateGenerationTrainingDataset::TRUSTED_REVIEW_DRAFT)
                    ->requiresConfirmation()
                    ->action(fn (EstimateGenerationTrainingDataset $record): mixed => self::runAction($record, 'submit_review')),
                Action::make('approve_review')
                    ->label(trans_message('estimate_generation.training_approve_review_action'))
                    ->visible(fn (EstimateGenerationTrainingDataset $record): bool => $record->dataset_type === EstimateGenerationTrainingDataset::TYPE_DEVELOPMENT
                        && $record->status === EstimateGenerationTrainingDataset::STATUS_REVIEW_REQUIRED
                        && $record->trusted_review_status === EstimateGenerationTrainingDataset::TRUSTED_REVIEW_PENDING)
                    ->requiresConfirmation()
                    ->action(fn (EstimateGenerationTrainingDataset $record): mixed => self::runAction($record, 'approve_review')),
                Action::make('reject_review')
                    ->label(trans_message('estimate_generation.training_reject_review_action'))
                    ->color('danger')
                    ->visible(fn (EstimateGenerationTrainingDataset $record): bool => $record->dataset_type === EstimateGenerationTrainingDataset::TYPE_DEVELOPMENT
                        && $record->status === EstimateGenerationTrainingDataset::STATUS_REVIEW_REQUIRED
                        && $record->trusted_review_status === EstimateGenerationTrainingDataset::TRUSTED_REVIEW_PENDING)
                    ->requiresConfirmation()
                    ->action(fn (EstimateGenerationTrainingDataset $record): mixed => self::runAction($record, 'reject_review')),
                Action::make('approve_primary')
                    ->label(trans_message('estimate_generation.training_approve_action'))
                    ->requiresConfirmation()
                    ->visible(fn (EstimateGenerationTrainingDataset $record): bool => $record->status === EstimateGenerationTrainingDataset::STATUS_REVIEW_REQUIRED
                        && ($record->dataset_type !== EstimateGenerationTrainingDataset::TYPE_DEVELOPMENT
                            || $record->trusted_review_status === EstimateGenerationTrainingDataset::TRUSTED_REVIEW_APPROVED))
                    ->action(fn (EstimateGenerationTrainingDataset $record): mixed => self::runAction($record, 'approve_primary')),
                DeleteAction::make()
                    ->visible(fn (EstimateGenerationTrainingDataset $record): bool => SystemAdminAccess::can(FilamentPermission::ESTIMATE_GENERATION_DATASETS)
                        && ! in_array($record->status, [EstimateGenerationTrainingDataset::STATUS_APPROVED, EstimateGenerationTrainingDataset::STATUS_ARCHIVED], true)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEstimateGenerationTrainingDatasets::route('/'),
            'create' => Pages\CreateEstimateGenerationTrainingDataset::route('/create'),
            'view' => Pages\ViewEstimateGenerationTrainingDataset::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return SystemAdminAccess::can(FilamentPermission::ESTIMATE_GENERATION_DATASETS);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canViewAny();
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof EstimateGenerationTrainingDataset && self::canViewAny();
    }

    public static function canCreate(): bool
    {
        return SystemAdminAccess::can(FilamentPermission::ESTIMATE_GENERATION_DATASETS);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof EstimateGenerationTrainingDataset
            && ! in_array($record->status, [EstimateGenerationTrainingDataset::STATUS_APPROVED, EstimateGenerationTrainingDataset::STATUS_ARCHIVED], true)
            && SystemAdminAccess::can(FilamentPermission::ESTIMATE_GENERATION_DATASETS);
    }

    public static function canDeleteAny(): bool
    {
        return SystemAdminAccess::can(FilamentPermission::ESTIMATE_GENERATION_DATASETS);
    }

    public static function canProcess(): bool
    {
        return SystemAdminAccess::can(FilamentPermission::ESTIMATE_GENERATION_OPERATE);
    }

    /**
     * @return array<string, string>
     */
    public static function sourceSystemOptions(): array
    {
        return [
            'grandsmeta' => trans_message('estimate_generation.training_source_grandsmeta'),
            'prohelper' => trans_message('estimate_generation.training_source_prohelper'),
            'manual' => trans_message('estimate_generation.training_source_manual'),
        ];
    }

    /** @return array<string, string> */
    public static function datasetTypeOptions(): array
    {
        return [
            EstimateGenerationTrainingDataset::TYPE_DEVELOPMENT => trans_message('estimate_generation.training_type_development'),
            EstimateGenerationTrainingDataset::TYPE_REGRESSION => trans_message('estimate_generation.training_type_regression'),
            EstimateGenerationTrainingDataset::TYPE_ACCEPTANCE => trans_message('estimate_generation.training_type_acceptance'),
        ];
    }

    /** @return array<string, string> */
    public static function trustedReviewStatusOptions(): array
    {
        return [
            EstimateGenerationTrainingDataset::TRUSTED_REVIEW_DRAFT => trans_message('estimate_generation.training_review_draft'),
            EstimateGenerationTrainingDataset::TRUSTED_REVIEW_PENDING => trans_message('estimate_generation.training_review_pending'),
            EstimateGenerationTrainingDataset::TRUSTED_REVIEW_APPROVED => trans_message('estimate_generation.training_review_approved'),
            EstimateGenerationTrainingDataset::TRUSTED_REVIEW_REJECTED => trans_message('estimate_generation.training_review_rejected'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return [
            EstimateGenerationTrainingDataset::STATUS_DRAFT => trans_message('estimate_generation.training_status_uploaded'),
            EstimateGenerationTrainingDataset::STATUS_PROCESSING => trans_message('estimate_generation.training_status_processing'),
            EstimateGenerationTrainingDataset::STATUS_REVIEW_REQUIRED => trans_message('estimate_generation.training_status_processed'),
            EstimateGenerationTrainingDataset::STATUS_APPROVED => trans_message('estimate_generation.training_status_processed'),
            EstimateGenerationTrainingDataset::STATUS_REJECTED => trans_message('estimate_generation.training_status_failed'),
            EstimateGenerationTrainingDataset::STATUS_ARCHIVED => trans_message('estimate_generation.training_status_processed'),
        ];
    }

    private static function statusLabel(?string $status): string
    {
        return self::statusOptions()[$status ?? ''] ?? (string) $status;
    }

    private static function qualityStatusLabel(?string $status): string
    {
        return match ($status) {
            'pending' => trans_message('estimate_generation.training_quality_pending'),
            'accepted' => trans_message('estimate_generation.training_quality_accepted'),
            'needs_review' => trans_message('estimate_generation.training_quality_needs_review'),
            'failed' => trans_message('estimate_generation.training_quality_failed'),
            default => (string) $status,
        };
    }

    /**
     * @return array<int, string>
     */
    private static function projectOptions(mixed $organizationId): array
    {
        if (! is_numeric($organizationId) || (int) $organizationId <= 0) {
            return [];
        }

        return Project::query()
            ->where('organization_id', (int) $organizationId)
            ->orderBy('name')
            ->limit(500)
            ->pluck('name', 'id')
            ->all();
    }

    public static function runAction(EstimateGenerationTrainingDataset $record, string $action): mixed
    {
        $actor = SystemAdminAccess::user();
        if ($actor === null || $record->organization_id === null) {
            return null;
        }

        $result = app(AdminTrainingDatasetActionService::class)->handle(new AdminTrainingDatasetActionCommand(
            actorId: (int) $actor->id,
            datasetId: (int) $record->id,
            organizationId: (int) $record->organization_id,
            expectedVersion: (int) ($record->control_version ?? 0),
            action: $action,
            idempotencyKey: sprintf('filament:dataset:%d:%s:v%d', (int) $record->id, $action, (int) ($record->control_version ?? 0)),
        ));

        Notification::make()
            ->success()
            ->title(trans_message($action === 'process'
                ? 'estimate_generation.training_process_queued'
                : 'estimate_generation.training_review_changed'))
            ->send();

        return $result;
    }
}
