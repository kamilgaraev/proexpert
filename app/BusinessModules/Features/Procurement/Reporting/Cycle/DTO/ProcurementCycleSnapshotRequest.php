<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ProcurementCycleSnapshotRequest
{
    private const FILTERS = [
        'award_amount_max', 'award_amount_min', 'buyer_id', 'cohort_basis', 'currency', 'current_stage',
        'material_category_id', 'material_id', 'outcome', 'period_end', 'period_start', 'priority',
        'project_ids', 'requester_id', 'supplier_party_id',
    ];

    public array $filters;

    public function __construct(
        public ReportScope $scope,
        array $filters,
        public DateTimeImmutable $asOf,
        public ?DateTimeImmutable $staleAt,
    ) {
        if ($staleAt !== null && $staleAt < $asOf) {
            throw new InvalidArgumentException('procurement_cycle_snapshot_request_invalid');
        }
        foreach (array_keys($filters) as $key) {
            if (! is_string($key) || ! in_array($key, self::FILTERS, true)) {
                throw new InvalidArgumentException('procurement_cycle_snapshot_filter_invalid');
            }
        }
        $this->filters = $this->normalize($filters);
        CanonicalJson::encode($this->filters);
    }

    public function projectIds(): array
    {
        return $this->filters['project_ids'] ?? $this->scope->projectIds;
    }

    private function normalize(array $filters): array
    {
        $result = [];
        foreach (self::FILTERS as $key) {
            if (! array_key_exists($key, $filters)) {
                continue;
            }
            $value = $filters[$key];
            if ($key === 'project_ids') {
                if (! is_array($value) || ! array_is_list($value)) {
                    throw new InvalidArgumentException('procurement_cycle_snapshot_filter_invalid');
                }
                $value = array_values(array_unique($value));
                sort($value, SORT_NUMERIC);
                foreach ($value as $projectId) {
                    if (! is_int($projectId) || ! in_array($projectId, $this->scope->projectIds, true)) {
                        throw new InvalidArgumentException('procurement_cycle_snapshot_scope_invalid');
                    }
                }
            } elseif (str_ends_with($key, '_id')) {
                if (! is_int($value) || $value < 1) {
                    throw new InvalidArgumentException('procurement_cycle_snapshot_filter_invalid');
                }
            } elseif (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException('procurement_cycle_snapshot_filter_invalid');
            }
            $result[$key] = $value;
        }
        if (isset($result['cohort_basis']) && ! in_array($result['cohort_basis'], ['start', 'outcome'], true)) {
            throw new InvalidArgumentException('procurement_cycle_snapshot_filter_invalid');
        }
        if (isset($result['currency']) && preg_match('/^[A-Z]{3}$/D', $result['currency']) !== 1) {
            throw new InvalidArgumentException('procurement_cycle_snapshot_filter_invalid');
        }
        foreach (['award_amount_min', 'award_amount_max'] as $amountKey) {
            if (isset($result[$amountKey])
                && preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/D', $result[$amountKey]) !== 1) {
                throw new InvalidArgumentException('procurement_cycle_snapshot_filter_invalid');
            }
        }
        foreach (['period_start', 'period_end'] as $dateKey) {
            if (isset($result[$dateKey])
                && DateTimeImmutable::createFromFormat('!Y-m-d', $result[$dateKey])?->format('Y-m-d') !== $result[$dateKey]) {
                throw new InvalidArgumentException('procurement_cycle_snapshot_filter_invalid');
            }
        }
        if (isset($result['period_start'], $result['period_end'])
            && $result['period_end'] < $result['period_start']) {
            throw new InvalidArgumentException('procurement_cycle_snapshot_filter_invalid');
        }
        if ((isset($result['award_amount_min']) || isset($result['award_amount_max']))
            && ! isset($result['currency'])) {
            throw new InvalidArgumentException('procurement_cycle_snapshot_filter_invalid');
        }

        return $result;
    }
}
