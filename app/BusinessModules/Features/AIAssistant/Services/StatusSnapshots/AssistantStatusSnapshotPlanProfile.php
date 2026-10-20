<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots;

final class AssistantStatusSnapshotPlanProfile
{
    private int $emitted = 0;

    public function sample(array $plan, float $selectMs, float $decodeMs, int $planBytes, int $queryBytes, int $bindings, int $ordinal): ?array
    {
        if (! is_finite($selectMs) || $selectMs < 500 || $this->emitted >= 8) {
            return null;
        }
        $this->emitted++;
        $summary = [
            'select_elapsed_ms' => round($selectMs, 2),
            'decode_elapsed_ms' => $this->number($decodeMs),
            'planning_ms' => $this->number($plan[0]['Planning Time'] ?? null),
            'plan_bytes' => max(0, $planBytes),
            'query_bytes' => max(0, $queryBytes),
            'bindings_count' => max(0, $bindings),
            'plan_ordinal' => max(1, $ordinal),
            'root_plan_rows' => $this->number($plan[0]['Plan']['Plan Rows'] ?? null),
            'root_total_cost' => $this->number($plan[0]['Plan']['Total Cost'] ?? null),
            'plan_nodes' => 0,
            'relation_nodes' => 0,
            'subplan_nodes' => 0,
            'max_array_depth' => 0,
            'entries_visited' => 0,
            'truncated' => false,
        ];
        $this->visit($plan, 0, $summary);

        return $summary;
    }

    private function visit(array $node, int $depth, array &$summary): void
    {
        $summary['max_array_depth'] = max($summary['max_array_depth'], $depth);
        if (isset($node['Node Type']) && is_string($node['Node Type'])) {
            $summary['plan_nodes']++;
        }
        if (isset($node['Relation Name']) && is_string($node['Relation Name'])) {
            $summary['relation_nodes']++;
        }
        if (isset($node['Subplan Name']) && is_string($node['Subplan Name'])) {
            $summary['subplan_nodes']++;
        }
        foreach ($node as $value) {
            if ($summary['entries_visited'] >= 4096) {
                $summary['truncated'] = true;

                return;
            }
            $summary['entries_visited']++;
            if (! is_array($value)) {
                continue;
            }
            if ($depth >= 128) {
                $summary['truncated'] = true;

                continue;
            }
            $this->visit($value, $depth + 1, $summary);
            if ($summary['entries_visited'] >= 4096) {
                $summary['truncated'] = true;

                return;
            }
        }
    }

    private function number(mixed $value): ?float
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < 0 || $value > 1.0e15) {
            return null;
        }

        return round((float) $value, 2);
    }
}
