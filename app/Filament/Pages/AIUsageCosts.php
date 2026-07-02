<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\BusinessModules\Features\AIAssistant\Services\AIUsageReportService;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\SystemAdminAccess;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;

use function trans_message;

class AIUsageCosts extends Page
{
    protected string $view = 'filament.pages.ai-usage-costs';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?int $navigationSort = 35;

    protected static ?string $slug = 'ai-usage-costs';

    protected Width|string|null $maxContentWidth = Width::SevenExtraLarge;

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $provider = '';

    public string $model = '';

    public string $operation = '';

    /**
     * @var array<string, mixed>
     */
    public array $report = [];

    public function mount(): void
    {
        $this->dateFrom = CarbonImmutable::now()->subDays(6)->toDateString();
        $this->dateTo = CarbonImmutable::now()->toDateString();

        $this->loadReport();
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return NavigationGroups::billing();
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('filament_navigation.ai_usage.label');
    }

    public function getTitle(): string|Htmlable
    {
        return trans_message('filament_ai_usage.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return trans_message('filament_ai_usage.subtitle');
    }

    public static function canAccess(): bool
    {
        return SystemAdminAccess::can(FilamentPermission::AI_USAGE_VIEW);
    }

    public function applyFilters(): void
    {
        $this->loadReport();
    }

    public function resetFilters(): void
    {
        $this->dateFrom = CarbonImmutable::now()->subDays(6)->toDateString();
        $this->dateTo = CarbonImmutable::now()->toDateString();
        $this->provider = '';
        $this->model = '';
        $this->operation = '';

        $this->loadReport();
    }

    public function loadReport(): void
    {
        $this->report = app(AIUsageReportService::class)->summary([
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'provider' => $this->provider,
            'model' => $this->model,
            'operation' => $this->operation,
        ]);
    }

    /**
     * @return array<string>
     */
    public function providerOptions(): array
    {
        return $this->uniqueValues($this->report['models'] ?? [], 'provider');
    }

    /**
     * @return array<string>
     */
    public function modelOptions(): array
    {
        return $this->uniqueValues($this->report['models'] ?? [], 'model');
    }

    /**
     * @return array<string>
     */
    public function operationOptions(): array
    {
        return $this->uniqueValues($this->report['operations'] ?? [], 'operation');
    }

    public function formatTokens(mixed $value): string
    {
        return number_format((int) $value, 0, ',', ' ');
    }

    public function formatMoney(mixed $value): string
    {
        $amount = (float) $value;
        $precision = $amount > 0 && $amount < 1 ? 4 : 2;

        return number_format($amount, $precision, ',', ' ').' ₽';
    }

    public function operationLabel(mixed $operation): string
    {
        $operation = (string) $operation;
        $label = trans_message("filament_ai_usage.operations.{$operation}");

        return $label === "filament_ai_usage.operations.{$operation}" ? $operation : $label;
    }

    /**
     * @param  iterable<mixed>  $rows
     * @return array<string>
     */
    private function uniqueValues(iterable $rows, string $key): array
    {
        $values = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $value = trim((string) ($row[$key] ?? ''));

            if ($value !== '') {
                $values[$value] = $value;
            }
        }

        ksort($values);

        return array_values($values);
    }
}
