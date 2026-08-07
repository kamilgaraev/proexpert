<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\Enums\CurrencyCode;
use InvalidArgumentException;

final class ProjectPortfolioHealthImmutableOwnerPayloadBuilder
{
    private const KINDS = [
        'project_margin',
        'wip_completion_forecast',
        'budget_plan_fact',
    ];

    private const RISK_RANK = [
        'low' => 1,
        'medium' => 2,
        'high' => 3,
        'critical' => 4,
    ];

    public function build(array $rowsByKind): array
    {
        foreach (self::KINDS as $kind) {
            if (! isset($rowsByKind[$kind])
                || ! is_array($rowsByKind[$kind])
                || ! array_is_list($rowsByKind[$kind])
                || $rowsByKind[$kind] === []) {
                throw new InvalidArgumentException('project_portfolio_health_owner_rows_invalid');
            }
        }

        $marginRows = $this->marginRows($rowsByKind['project_margin']);
        $wipRows = $this->wipRows($rowsByKind['wip_completion_forecast']);
        $forecastMargins = [];
        foreach ($marginRows as $row) {
            $forecastMargins[$this->payloadKey($row)] = $row['forecast']['gross_margin'];
        }
        foreach ($wipRows as &$row) {
            $key = $this->payloadKey($row);
            if (isset($forecastMargins[$key])) {
                $row['metrics']['forecast_gross_margin'] = $forecastMargins[$key];
            }
        }
        unset($row);

        return [
            'project_margin' => ['rows' => $marginRows],
            'wip_completion_forecast' => ['rows' => $wipRows],
            'budget_plan_fact' => ['rows' => $this->planFactRows($rowsByKind['budget_plan_fact'])],
        ];
    }

    private function marginRows(array $rows): array
    {
        $buckets = [];
        foreach ($rows as $row) {
            $identity = $this->identity($row);
            $key = $identity['key'];
            $buckets[$key] ??= [
                ...$identity,
                'actual_revenue_minor' => 0,
                'actual_cost_minor' => 0,
                'forecast_revenue_minor' => 0,
                'forecast_cost_minor' => 0,
                'source_refs' => [],
            ];
            $this->assertSameProject($buckets[$key], $identity);
            foreach ([
                'actual_revenue_minor',
                'actual_cost_minor',
                'forecast_revenue_minor',
                'forecast_cost_minor',
            ] as $field) {
                $buckets[$key][$field] = $this->checkedAdd(
                    $buckets[$key][$field],
                    $this->minor($row[$field] ?? null),
                );
            }
            $this->mergeSourceRefs($buckets[$key]['source_refs'], $row['source_refs'] ?? null);
        }

        return $this->sorted(array_map(function (array $bucket): array {
            $actualRevenue = $this->money($bucket['actual_revenue_minor']);
            $actualCost = $this->money($bucket['actual_cost_minor']);
            $forecastRevenue = $this->money($bucket['forecast_revenue_minor']);
            $forecastCost = $this->money($bucket['forecast_cost_minor']);

            return [
                'project' => ['id' => $bucket['project_id'], 'name' => $bucket['project_name']],
                'currency' => $bucket['currency'],
                'actual' => [
                    'revenue' => $actualRevenue,
                    'cost' => $actualCost,
                    'gross_margin' => $this->money($this->checkedSubtract(
                        $bucket['actual_revenue_minor'],
                        $bucket['actual_cost_minor'],
                    )),
                ],
                'forecast' => [
                    'revenue' => $forecastRevenue,
                    'cost' => $forecastCost,
                    'gross_margin' => $this->money($this->checkedSubtract(
                        $bucket['forecast_revenue_minor'],
                        $bucket['forecast_cost_minor'],
                    )),
                ],
                'source_refs' => array_values($bucket['source_refs']),
            ];
        }, array_values($buckets)));
    }

    private function wipRows(array $rows): array
    {
        $buckets = [];
        foreach ($rows as $row) {
            $identity = $this->identity($row);
            $key = $identity['key'];
            $buckets[$key] ??= [
                ...$identity,
                'ac_minor' => 0,
                'wip_minor' => 0,
                'ctc_minor' => 0,
                'eac_minor' => 0,
                'source_refs' => [],
            ];
            $this->assertSameProject($buckets[$key], $identity);
            foreach (['ac_minor', 'wip_minor', 'ctc_minor', 'eac_minor'] as $field) {
                $buckets[$key][$field] = $this->checkedAdd(
                    $buckets[$key][$field],
                    $this->minor($row[$field] ?? null),
                );
            }
            $this->mergeSourceRefs($buckets[$key]['source_refs'], $row['source_refs'] ?? null);
        }

        return $this->sorted(array_map(function (array $bucket): array {
            return [
                'project' => ['id' => $bucket['project_id'], 'name' => $bucket['project_name']],
                'currency' => $bucket['currency'],
                'metrics' => [
                    'wip' => $this->money($bucket['wip_minor']),
                    'wip_total' => $this->money($bucket['wip_minor']),
                    'ftc' => $this->money($this->checkedSubtract($bucket['eac_minor'], $bucket['ac_minor'])),
                    'eac' => $this->money($bucket['eac_minor']),
                    'ctc' => $this->money($bucket['ctc_minor']),
                ],
                'source_refs' => array_values($bucket['source_refs']),
            ];
        }, array_values($buckets)));
    }

    private function planFactRows(array $rows): array
    {
        $buckets = [];
        foreach ($rows as $row) {
            $identity = $this->identity($row);
            $key = $identity['key'];
            $risk = $row['risk'] ?? null;
            if (! is_string($risk) || ! array_key_exists($risk, self::RISK_RANK)) {
                throw new InvalidArgumentException('project_portfolio_health_owner_risk_invalid');
            }
            $buckets[$key] ??= [
                ...$identity,
                'variance_minor' => 0,
                'risk' => 'low',
                'source_refs' => [],
            ];
            $this->assertSameProject($buckets[$key], $identity);
            $buckets[$key]['variance_minor'] = $this->checkedAdd(
                $buckets[$key]['variance_minor'],
                $this->minor($row['variance_minor'] ?? null),
            );
            if (self::RISK_RANK[$risk] > self::RISK_RANK[$buckets[$key]['risk']]) {
                $buckets[$key]['risk'] = $risk;
            }
            $this->mergeSourceRefs($buckets[$key]['source_refs'], $row['source_refs'] ?? null);
        }

        return $this->sorted(array_map(function (array $bucket): array {
            return [
                'project' => ['id' => $bucket['project_id'], 'name' => $bucket['project_name']],
                'currency' => $bucket['currency'],
                'variance_amount' => $this->money($bucket['variance_minor']),
                'risk_level' => $bucket['risk'],
                'source_refs' => array_values($bucket['source_refs']),
            ];
        }, array_values($buckets)));
    }

    private function identity(mixed $row): array
    {
        if (! is_array($row)
            || ! is_string($row['row_key'] ?? null)
            || trim($row['row_key']) === ''
            || (! is_int($row['project_id'] ?? null)
                && (! is_string($row['project_id'] ?? null)
                    || preg_match('/^[1-9][0-9]*$/D', $row['project_id']) !== 1))
            || (int) $row['project_id'] < 1
            || ! is_string($row['project_name'] ?? null)
            || trim($row['project_name']) === ''
            || ! is_string($row['currency'] ?? null)) {
            throw new InvalidArgumentException('project_portfolio_health_owner_row_invalid');
        }
        $currency = mb_strtoupper(trim($row['currency']));
        if (CurrencyCode::tryFrom($currency) === null) {
            throw new InvalidArgumentException('project_portfolio_health_owner_currency_invalid');
        }
        $projectId = (int) $row['project_id'];

        return [
            'key' => $projectId.'|'.$currency,
            'project_id' => $projectId,
            'project_name' => trim($row['project_name']),
            'currency' => $currency,
        ];
    }

    private function assertSameProject(array $bucket, array $identity): void
    {
        if ($bucket['project_id'] !== $identity['project_id']
            || $bucket['project_name'] !== $identity['project_name']
            || $bucket['currency'] !== $identity['currency']) {
            throw new InvalidArgumentException('project_portfolio_health_owner_identity_conflict');
        }
    }

    private function mergeSourceRefs(array &$target, mixed $sourceRefs): void
    {
        if (! is_array($sourceRefs) || ! array_is_list($sourceRefs)) {
            throw new InvalidArgumentException('project_portfolio_health_owner_source_refs_invalid');
        }
        foreach ($sourceRefs as $sourceRef) {
            if (! is_array($sourceRef)
                || ! is_string($sourceRef['type'] ?? null)
                || trim($sourceRef['type']) === ''
                || (! is_int($sourceRef['id'] ?? null) && ! is_string($sourceRef['id'] ?? null))
                || trim((string) $sourceRef['id']) === ''
                || (is_int($sourceRef['id']) && $sourceRef['id'] < 1)) {
                throw new InvalidArgumentException('project_portfolio_health_owner_source_refs_invalid');
            }
            $type = trim($sourceRef['type']);
            $id = $sourceRef['id'];
            $target[$type.':'.(string) $id] = ['type' => $type, 'id' => $id];
        }
        ksort($target, SORT_STRING);
    }

    private function minor(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (! is_string($value) || preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new InvalidArgumentException('project_portfolio_health_owner_minor_invalid');
        }
        $normalized = (int) $value;
        if ((string) $normalized !== ltrim($value, '+')) {
            throw new InvalidArgumentException('project_portfolio_health_owner_minor_invalid');
        }

        return $normalized;
    }

    private function checkedAdd(int $left, int $right): int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)) {
            throw new InvalidArgumentException('project_portfolio_health_owner_minor_overflow');
        }

        return $left + $right;
    }

    private function checkedSubtract(int $left, int $right): int
    {
        if (($right < 0 && $left > PHP_INT_MAX + $right)
            || ($right > 0 && $left < PHP_INT_MIN + $right)) {
            throw new InvalidArgumentException('project_portfolio_health_owner_minor_overflow');
        }

        return $left - $right;
    }

    private function money(int $minor): string
    {
        $whole = intdiv($minor, 100);
        $fraction = abs($minor % 100);
        $prefix = $minor < 0 && $whole === 0 ? '-' : '';

        return $prefix.$whole.'.'.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
    }

    private function sorted(array $rows): array
    {
        usort($rows, static fn (array $left, array $right): int => [
            $left['project']['id'],
            $left['currency'],
        ] <=> [
            $right['project']['id'],
            $right['currency'],
        ]);

        return $rows;
    }

    private function payloadKey(array $row): string
    {
        $project = is_array($row['project'] ?? null) ? $row['project'] : [];

        return (string) ($project['id'] ?? '').'|'.(string) ($row['currency'] ?? '');
    }
}
