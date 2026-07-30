<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use App\Support\Reporting\ReportScopedResourceFilter;
use InvalidArgumentException;

final readonly class AcceptedProductionEventUniverse
{
    public function resolve(ReportScope $scope, ReportQuery $query): array
    {
        $resources = new ReportScopedResourceFilter;
        $workIds = $this->intersect(
            $resources->ids($scope, ['work', 'completed_work'], $scope->projectIds),
            $this->positiveIntegerFilter($query, 'work_ids'),
        );
        $actIds = $this->intersect(
            $resources->ids(
                $scope,
                ['act', 'performance_act', 'contract_performance_act'],
                $scope->projectIds,
            ),
            $this->positiveIntegerFilter($query, 'act_ids', 'performance_act_ids'),
        );
        $actLineIds = $resources->ids(
            $scope,
            ['act_line', 'performance_act_line'],
            $scope->projectIds,
        );
        $projectIds = array_values(array_intersect(
            $scope->projectIds,
            $this->positiveIntegerFilter($query, 'project_ids') ?? $scope->projectIds,
        ));

        $events = ProductionAcceptanceEvent::query()
            ->where('organization_id', $scope->organizationId)
            ->whereIn('project_id', $projectIds)
            ->where('recognized_at', '<=', $query->asOf)
            ->orderBy('performance_act_id')
            ->orderBy('source_line_type')
            ->orderBy('source_line_id')
            ->orderBy('transition_version')
            ->get()
            ->filter(function (ProductionAcceptanceEvent $event) use (
                $workIds,
                $actIds,
                $actLineIds,
                $query,
            ): bool {
                if ($workIds !== null
                    && ($event->work_id === null || ! in_array((int) $event->work_id, $workIds, true))
                ) {
                    return false;
                }
                if ($actIds !== null && ! in_array((int) $event->performance_act_id, $actIds, true)) {
                    return false;
                }
                if ($actLineIds !== null
                    && ((string) $event->source_line_type !== 'performance_act_line'
                        || ! in_array((int) $event->source_line_id, $actLineIds, true))
                ) {
                    return false;
                }

                return $this->matchesFilters($query, $event);
            })
            ->values();

        return [
            'act_ids' => $actIds,
            'act_line_ids' => $actLineIds,
            'events' => $events,
            'project_ids' => $projectIds,
            'restrict_to_event_keys' => $this->hasNonIdentityFilters($query),
            'restrict_source_lines' => $workIds !== null
                || $actLineIds !== null
                || $this->hasNonIdentityFilters($query),
            'work_ids' => $workIds,
        ];
    }

    private function matchesFilters(ReportQuery $query, ProductionAcceptanceEvent $event): bool
    {
        $values = $query->filters->values;
        $period = $values['period'] ?? [];
        $from = $values['period_from'] ?? (is_array($period) ? ($period['from'] ?? null) : null);
        $to = $values['period_to'] ?? (is_array($period) ? ($period['to'] ?? null) : null);
        foreach ([$from, $to] as $date) {
            if ($date !== null
                && (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1)
            ) {
                throw new InvalidArgumentException('accepted_production_period_filter_invalid');
            }
        }
        if ($from !== null && $to !== null && $from > $to) {
            throw new InvalidArgumentException('accepted_production_period_filter_invalid');
        }
        $recognizedOn = $event->recognized_at->format('Y-m-d');

        return $this->matches($values['contractor_ids'] ?? [], $event->contractor_id)
            && $this->matches($values['unit_codes'] ?? [], (string) $event->unit_code)
            && $this->matches($values['zones'] ?? [], $event->zone)
            && $this->matches($values['statuses'] ?? [], (string) $event->event_type)
            && ($from === null || $recognizedOn >= $from)
            && ($to === null || $recognizedOn <= $to);
    }

    private function positiveIntegerFilter(
        ReportQuery $query,
        string $key,
        ?string $alias = null,
    ): ?array {
        $value = $query->filters->values[$key]
            ?? ($alias === null ? null : ($query->filters->values[$alias] ?? null));
        if ($value === null || $value === []) {
            return null;
        }
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('accepted_production_filter_invalid');
        }
        $result = array_values(array_unique(array_map('intval', $value)));
        if (array_filter($result, static fn (int $id): bool => $id < 1) !== []) {
            throw new InvalidArgumentException('accepted_production_filter_invalid');
        }

        return $result;
    }

    private function intersect(?array $left, ?array $right): ?array
    {
        if ($left === null) {
            return $right;
        }
        if ($right === null) {
            return $left;
        }

        return array_values(array_intersect($left, $right));
    }

    private function matches(mixed $filter, int|string|null $value): bool
    {
        if ($filter === []) {
            return true;
        }
        if (! is_array($filter) || ! array_is_list($filter) || $value === null) {
            return false;
        }

        return in_array((string) $value, array_map('strval', $filter), true);
    }

    private function hasNonIdentityFilters(ReportQuery $query): bool
    {
        $values = $query->filters->values;

        return ($values['contractor_ids'] ?? []) !== []
            || ($values['unit_codes'] ?? []) !== []
            || ($values['zones'] ?? []) !== []
            || ($values['statuses'] ?? []) !== []
            || ($values['period'] ?? []) !== []
            || isset($values['period_from'])
            || isset($values['period_to']);
    }
}
