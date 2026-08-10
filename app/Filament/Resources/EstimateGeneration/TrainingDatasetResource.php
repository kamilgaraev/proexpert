<?php

declare(strict_types=1);

namespace App\Filament\Resources\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationTrainingDataset;
use App\Filament\Resources\EstimateGeneration\TrainingDatasetResource\Pages;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\SystemAdminAccess;
use App\Filament\Support\TableEmptyState;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
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
        return $schema->components([]);
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
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEstimateGenerationTrainingDatasets::route('/'),
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
}
