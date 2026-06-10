<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Console\Commands;

use App\BusinessModules\Features\Budgeting\DTOs\EpmDataMartScope;
use App\BusinessModules\Features\Budgeting\Services\EpmDataMartRecalculationCoordinator;
use App\BusinessModules\Features\Budgeting\Services\EpmDataMartRecalculationService;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

use function trans_message;

final class RecalculateEpmDataMartSnapshotsCommand extends Command
{
    protected $signature = 'budgeting:epm-data-mart:recalculate
                            {--organization=* : Organization id to process}
                            {--scope=* : Report scope to recalculate}
                            {--period-start= : Period start date}
                            {--period-end= : Period end date}
                            {--as-of-date= : As-of date}
                            {--project-id= : Project id}
                            {--currency= : Currency code}
                            {--granularity=day : Cash gap granularity}
                            {--scenario=base : Cash gap scenario}
                            {--limit=50 : Maximum active organizations when organization is omitted}
                            {--sync : Run recalculation in the current process}';

    protected $description = 'Запланировать пересчет управленческой EPM-витрины данных.';

    public function handle(
        EpmDataMartRecalculationCoordinator $coordinator,
        EpmDataMartRecalculationService $recalculationService,
    ): int {
        try {
            $scopes = $this->scopes();
        } catch (InvalidArgumentException) {
            $this->error(trans_message('budgeting.epm_data_mart.messages.command_invalid_scope'));

            return self::FAILURE;
        }

        $organizationIds = $this->organizationIds();
        if ($organizationIds === []) {
            $this->warn(trans_message('budgeting.epm_data_mart.messages.command_no_organizations'));

            return self::SUCCESS;
        }

        $queued = 0;
        $failed = 0;

        foreach ($organizationIds as $organizationId) {
            foreach ($scopes as $reportScope) {
                try {
                    $scope = EpmDataMartScope::fromInput(
                        $reportScope,
                        $this->inputForScope($reportScope, $organizationId),
                    );
                    if ((bool) $this->option('sync')) {
                        [$run, $created] = $coordinator->createOrReuseQueuedRun($scope);
                        if (!$created) {
                            $this->warn(trans_message('budgeting.epm_data_mart.messages.command_scope_already_active', [
                                'scope' => $reportScope,
                                'organization' => (string) $organizationId,
                            ]));

                            continue;
                        }

                        $queued++;
                        $recalculationService->recalculateRun((int) $run->id);
                    } else {
                        $coordinator->queue($scope);
                        $queued++;
                    }
                } catch (Throwable) {
                    $failed++;
                    $this->warn(trans_message('budgeting.epm_data_mart.messages.command_scope_failed', [
                        'scope' => $reportScope,
                        'organization' => (string) $organizationId,
                    ]));
                }
            }
        }

        $this->info(trans_message('budgeting.epm_data_mart.messages.command_summary', [
            'queued' => (string) $queued,
            'failed' => (string) $failed,
        ]));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function scopes(): array
    {
        $options = $this->optionValues('scope');
        if ($options === [] || in_array('all', $options, true)) {
            return EpmDataMartScope::SUPPORTED_REPORT_SCOPES;
        }

        return array_values(array_unique(array_map(
            static fn (string $scope): string => EpmDataMartScope::normalizeReportScope($scope),
            $options,
        )));
    }

    private function organizationIds(): array
    {
        $options = $this->optionValues('organization');
        if ($options !== []) {
            return array_values(array_unique(array_filter(
                array_map(static fn (string $value): int => (int) $value, $options),
                static fn (int $value): bool => $value > 0,
            )));
        }

        $limit = max(1, min(1000, (int) $this->option('limit')));

        return Organization::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();
    }

    private function inputForScope(string $reportScope, int $organizationId): array
    {
        $periodStart = $this->dateOption('period-start') ?? CarbonImmutable::today()->startOfMonth()->toDateString();
        $periodEnd = $this->dateOption('period-end') ?? CarbonImmutable::today()->endOfMonth()->toDateString();
        $asOfDate = $this->dateOption('as-of-date') ?? $periodEnd;
        $projectId = $this->positiveIntOption('project-id');
        $currency = $this->currencyOption();

        $input = array_filter([
            'organization_id' => $organizationId,
            'current_organization_id' => $organizationId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'as_of_date' => $asOfDate,
            'project_id' => $projectId,
            'currency' => $currency,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        if ($reportScope === EpmDataMartScope::CASH_GAP) {
            $input['granularity'] = $this->stringOption('granularity') ?? 'day';
            $input['scenario'] = $this->stringOption('scenario') ?? 'base';
        }

        return $input;
    }

    private function optionValues(string $name): array
    {
        $rawValues = (array) $this->option($name);
        $values = [];

        foreach ($rawValues as $rawValue) {
            if (!is_scalar($rawValue)) {
                continue;
            }

            foreach (explode(',', (string) $rawValue) as $value) {
                $value = trim($value);
                if ($value !== '') {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }

    private function dateOption(string $name): ?string
    {
        $value = $this->stringOption($name);

        return $value === null ? null : CarbonImmutable::parse($value)->toDateString();
    }

    private function positiveIntOption(string $name): ?int
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
    }

    private function currencyOption(): ?string
    {
        $value = $this->stringOption('currency');

        return $value === null ? null : mb_strtoupper($value);
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
