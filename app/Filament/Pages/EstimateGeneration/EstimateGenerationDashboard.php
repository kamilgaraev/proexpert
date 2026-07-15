<?php

declare(strict_types=1);

namespace App\Filament\Pages\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Enums\EstimateGenerationMode;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\SystemAdminAccess;
use App\Filament\Widgets\EstimateGeneration\CostTrendWidget;
use App\Filament\Widgets\EstimateGeneration\QueueHealthWidget;
use App\Filament\Widgets\EstimateGeneration\SessionStatsWidget;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EstimateGenerationDashboard extends Dashboard
{
    use HasFiltersForm;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?int $navigationSort = 1;

    protected static string $routePath = '/estimate-generation';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return NavigationGroups::aiEstimator();
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('estimate_generation.dashboard.navigation');
    }

    public function getTitle(): string
    {
        return trans_message('estimate_generation.dashboard.title');
    }

    public static function canAccess(): bool
    {
        return SystemAdminAccess::can(FilamentPermission::ESTIMATE_GENERATION_MONITOR);
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(trans_message('estimate_generation.dashboard.filters'))
                ->schema([
                    DatePicker::make('date_from')->label(trans_message('estimate_generation.dashboard.date_from'))->default(now()->subDays(29)),
                    DatePicker::make('date_to')->label(trans_message('estimate_generation.dashboard.date_to'))->default(now()),
                    TextInput::make('organization_id')->label(trans_message('estimate_generation.dashboard.organization'))->numeric()->minValue(1),
                    TextInput::make('project_id')->label(trans_message('estimate_generation.dashboard.project'))->numeric()->minValue(1),
                    TextInput::make('provider')->label(trans_message('estimate_generation.dashboard.provider'))->maxLength(80),
                    TextInput::make('model')->label(trans_message('estimate_generation.dashboard.model'))->maxLength(160),
                    Select::make('stage')->label(trans_message('estimate_generation.dashboard.stage'))->options(self::stageOptions()),
                    Select::make('status')->label(trans_message('estimate_generation.dashboard.status'))->options(self::statusOptions()),
                    Select::make('document_type')->label(trans_message('estimate_generation.dashboard.document_type'))->options(self::documentTypeOptions()),
                    Select::make('mode')->label(trans_message('estimate_generation.dashboard.mode'))->options(self::modeOptions()),
                ])->columns(5),
        ]);
    }

    /** @return list<class-string> */
    public function getWidgets(): array
    {
        return [SessionStatsWidget::class, QueueHealthWidget::class, CostTrendWidget::class];
    }

    /** @return array<string, string> */
    private static function stageOptions(): array
    {
        return array_column(array_map(static fn (ProcessingStage $stage): array => [
            trans_message('estimate_generation.dashboard.stages.'.$stage->value), $stage->value,
        ], ProcessingStage::cases()), 0, 1);
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return array_column(array_map(static fn (EstimateGenerationStatus $status): array => [
            trans_message('estimate_generation.dashboard.statuses.'.$status->value), $status->value,
        ], EstimateGenerationStatus::cases()), 0, 1);
    }

    /** @return array<string, string> */
    private static function modeOptions(): array
    {
        return array_column(array_map(static fn (EstimateGenerationMode $mode): array => [
            trans_message('estimate_generation.dashboard.modes.'.$mode->value), $mode->value,
        ], EstimateGenerationMode::cases()), 0, 1);
    }

    /** @return array<string, string> */
    private static function documentTypeOptions(): array
    {
        return [
            'application/pdf' => 'PDF',
            'image/jpeg' => 'JPEG',
            'image/png' => 'PNG',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'XLSX',
            'application/vnd.ms-excel' => 'XLS',
        ];
    }
}
