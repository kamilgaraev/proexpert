<?php

declare(strict_types=1);

namespace App\Filament\Pages\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsData;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsService;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\SystemAdminAccess;
use App\Models\Organization;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/** @property Schema $form */
final class EstimateGenerationSettings extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected string $view = 'filament.pages.estimate-generation.settings';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?int $navigationSort = 12;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return NavigationGroups::aiEstimator();
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('estimate_generation.settings_navigation_label');
    }

    public function getTitle(): string
    {
        return trans_message('estimate_generation.settings_title');
    }

    public static function canAccess(): bool
    {
        return SystemAdminAccess::canAll([
            FilamentPermission::ESTIMATE_GENERATION_SETTINGS,
            FilamentPermission::ESTIMATE_GENERATION_BUDGETS,
        ]);
    }

    public function mount(): void
    {
        $data = $this->defaults();
        $current = app(EstimateGenerationSettingsService::class)->currentSnapshot('global', null);
        if ($current !== null) {
            $data = [
                ...$data,
                ...$current['snapshot'],
                'scope' => 'global',
                'organization_id' => null,
                'expected_version' => $current['version'],
                'idempotency_key' => (string) Str::ulid(),
            ];
        }
        $this->form->fill($data);
    }

    public function form(Schema $schema): Schema
    {
        $stages = [
            'vision' => trans_message('estimate_generation.settings_stage_vision'),
            'classification' => trans_message('estimate_generation.settings_stage_classification'),
            'planning' => trans_message('estimate_generation.settings_stage_planning'),
            'normative_matching' => trans_message('estimate_generation.settings_stage_normative_matching'),
            'pricing' => trans_message('estimate_generation.settings_stage_pricing'),
        ];
        $components = [
            Section::make(trans_message('estimate_generation.settings_scope_section'))->schema([
                Select::make('scope')->label(trans_message('estimate_generation.settings_scope'))->options([
                    'global' => trans_message('estimate_generation.settings_scope_global'),
                    'organization' => trans_message('estimate_generation.settings_scope_organization'),
                ])->live()->required(),
                Select::make('organization_id')->label(trans_message('estimate_generation.training_organization'))
                    ->options(fn (): array => Organization::query()->orderBy('name')->limit(500)->pluck('name', 'id')->all())
                    ->visible(fn ($get): bool => $get('scope') === 'organization')->searchable(),
                TextInput::make('expected_version')->label(trans_message('estimate_generation.settings_expected_version'))->integer()->minValue(0)->required(),
                TextInput::make('idempotency_key')->hidden()->required(),
            ])->columns(2),
            Section::make(trans_message('estimate_generation.settings_models_section'))->schema(array_map(
                static fn (string $label, string $stage): TextInput => TextInput::make("models.{$stage}")->label($label)->required()->maxLength(192),
                $stages,
                array_keys($stages),
            ))->columns(2),
            Section::make(trans_message('estimate_generation.settings_limits_section'))->schema([
                TextInput::make('limits.max_files')->label(trans_message('estimate_generation.settings_max_files'))->integer()->minValue(1)->maxValue(100)->required(),
                TextInput::make('limits.max_pages_per_file')->label(trans_message('estimate_generation.settings_max_pages_per_file'))->integer()->minValue(1)->maxValue(2000)->required(),
                TextInput::make('limits.max_total_pages')->label(trans_message('estimate_generation.settings_max_total_pages'))->integer()->minValue(1)->maxValue(10000)->required(),
            ])->columns(3),
        ];
        foreach ($stages as $stage => $label) {
            $components[] = Section::make($label)->schema([
                TextInput::make("timeouts.{$stage}")->label(trans_message('estimate_generation.settings_timeout'))->integer()->minValue(1)->maxValue(3600)->required(),
                TextInput::make("retries.{$stage}")->label(trans_message('estimate_generation.settings_retries'))->integer()->minValue(0)->maxValue(5)->required(),
            ])->columns(2)->compact();
        }
        $components[] = Section::make(trans_message('estimate_generation.settings_confidence_section'))->schema([
            TextInput::make('confidence.classification')->label(trans_message('estimate_generation.settings_confidence_classification'))->required(),
            TextInput::make('confidence.geometry')->label(trans_message('estimate_generation.settings_confidence_geometry'))->required(),
            TextInput::make('confidence.normative_matching')->label(trans_message('estimate_generation.settings_confidence_normative'))->required(),
            TextInput::make('confidence.pricing')->label(trans_message('estimate_generation.settings_confidence_pricing'))->required(),
        ])->columns(2);
        $components[] = Section::make(trans_message('estimate_generation.settings_formats_section'))->schema([
            CheckboxList::make('enabled_formats')->label(trans_message('estimate_generation.settings_enabled_formats'))->options(array_combine(
                ['pdf', 'jpg', 'jpeg', 'png', 'tiff', 'dxf', 'dwg', 'xlsx'],
                ['PDF', 'JPG', 'JPEG', 'PNG', 'TIFF', 'DXF', 'DWG', 'XLSX'],
            ))->columns(4)->required(),
        ]);
        $components[] = Section::make(trans_message('estimate_generation.settings_review_section'))->schema([
            Toggle::make('manual_review.low_confidence')->label(trans_message('estimate_generation.settings_review_low_confidence')),
            Toggle::make('manual_review.missing_evidence')->label(trans_message('estimate_generation.settings_review_missing_evidence')),
            Toggle::make('manual_review.price_outlier')->label(trans_message('estimate_generation.settings_review_price_outlier')),
            Toggle::make('manual_review.normative_fallback')->label(trans_message('estimate_generation.settings_review_normative_fallback')),
        ])->columns(2);
        $components[] = Section::make(trans_message('estimate_generation.settings_budgets_section'))->schema([
            TextInput::make('budgets.daily')->label(trans_message('estimate_generation.settings_daily_budget'))->regex('/^(?:0|[1-9]\d{0,17})\.\d{2}$/')->required(),
            TextInput::make('budgets.monthly')->label(trans_message('estimate_generation.settings_monthly_budget'))->regex('/^(?:0|[1-9]\d{0,17})\.\d{2}$/')->required(),
            Select::make('budgets.currency')->label(trans_message('estimate_generation.settings_currency'))->options(['RUB' => 'RUB', 'USD' => 'USD', 'EUR' => 'EUR'])->required(),
        ])->columns(3);

        return $schema->components($components)->statePath('data');
    }

    /** @return list<Action> */
    protected function getFormActions(): array
    {
        return [Action::make('save')->label(trans_message('estimate_generation.settings_save'))->submit('save')];
    }

    public function save(): void
    {
        $actor = SystemAdminAccess::user();
        abort_unless($actor !== null && self::canAccess(), 403);
        $settings = EstimateGenerationSettingsData::fromArray($this->form->getState());
        $result = app(EstimateGenerationSettingsService::class)->change((int) $actor->id, $settings);
        $this->data['expected_version'] = $result['version'];
        $this->data['idempotency_key'] = (string) Str::ulid();
        Notification::make()->success()->title(trans_message('estimate_generation.settings_saved'))->send();
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        $stages = ['vision', 'classification', 'planning', 'normative_matching', 'pricing'];

        return [
            'scope' => 'global', 'organization_id' => null, 'expected_version' => 0, 'idempotency_key' => (string) Str::ulid(),
            'models' => array_fill_keys($stages, 'openai/gpt-5'),
            'limits' => ['max_files' => 20, 'max_pages_per_file' => 500, 'max_total_pages' => 2000],
            'timeouts' => array_fill_keys($stages, 120), 'retries' => array_fill_keys($stages, 2),
            'confidence' => ['classification' => '0.8000', 'geometry' => '0.7500', 'normative_matching' => '0.8500', 'pricing' => '0.9000'],
            'enabled_formats' => ['pdf', 'jpg', 'jpeg', 'png', 'tiff', 'dxf', 'dwg', 'xlsx'],
            'manual_review' => ['low_confidence' => true, 'missing_evidence' => true, 'price_outlier' => true, 'normative_fallback' => true],
            'budgets' => ['daily' => '0.00', 'monthly' => '0.00', 'currency' => 'RUB'],
        ];
    }
}
