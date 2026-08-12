<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class DeterministicEstimateChangePreview
{
    /** @return array{state:string,delta:?string,blockers:string[],affected:array<int,array<string,mixed>>} */
    public function calculate(EstimateGenerationSession $session, EstimateCommandInterpretation $interpretation): array
    {
        $payload = $interpretation->payload;
        $dependencies = array_values(array_filter(array_map('strval', is_array($payload['dependency_keys'] ?? null) ? $payload['dependency_keys'] : [])));
        $rows = $this->rows(is_array($session->draft_payload) ? $session->draft_payload : []);
        $affected = array_values(array_filter($rows, static function (array $row) use ($dependencies): bool {
            $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
            $keys = array_values(array_filter(array_map('strval', is_array($metadata['dependency_keys'] ?? null) ? $metadata['dependency_keys'] : [])));

            return array_intersect($dependencies, $keys) !== [];
        }));
        if ($affected === []) {
            return ['state' => 'unknown', 'delta' => null, 'blockers' => ['affected_rows_not_found'], 'affected' => []];
        }

        if ($interpretation->kind() === 'select_technology') {
            return $this->technology($session, $payload, $affected);
        }

        if ($interpretation->kind() !== 'correct_fact') {
            return ['state' => 'unknown', 'delta' => null, 'blockers' => ['deterministic_recalculation_unavailable'], 'affected' => $this->summaries($affected)];
        }

        $beforeTotal = BigDecimal::zero();
        $blockers = [];
        foreach ($affected as $row) {
            if (($row['pricing_status'] ?? 'calculated') !== 'calculated' || ! $this->decimal($row['total_cost'] ?? null)) {
                $blockers[] = (string) ($row['pricing_blocker'] ?? 'price_unavailable');

                continue;
            }
            $beforeTotal = $beforeTotal->plus((string) $row['total_cost']);
        }
        if ($blockers !== []) {
            return ['state' => count($blockers) < count($affected) ? 'partial' : 'unknown', 'delta' => null, 'blockers' => array_values(array_unique($blockers)), 'affected' => $this->summaries($affected)];
        }
        $draft = is_array($session->draft_payload) ? $session->draft_payload : [];
        $simulationKey = $dependencies[0] ?? '';
        $simulation = is_array($draft['preview_simulations'][$simulationKey] ?? null) ? $draft['preview_simulations'][$simulationKey] : [];
        if (! $this->decimal($simulation['after_total'] ?? null)) {
            return ['state' => 'unknown', 'delta' => null, 'blockers' => ['canonical_preview_unavailable'], 'affected' => $this->summaries($affected)];
        }
        $delta = BigDecimal::of((string) $simulation['after_total'])->minus($beforeTotal);

        return ['state' => 'known', 'delta' => $delta->toScale(4, RoundingMode::HALF_UP)->__toString(), 'blockers' => [], 'affected' => $this->summaries($affected)];
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(array $draft): array
    {
        $rows = [];
        foreach ($draft['local_estimates'] ?? [] as $estimate) {
            foreach (is_array($estimate) ? ($estimate['sections'] ?? []) : [] as $section) {
                foreach (is_array($section) ? ($section['work_items'] ?? []) : [] as $row) {
                    if (is_array($row)) {
                        $rows[] = $row;
                    }
                }
            }
        }

        return $rows;
    }

    private function decimal(mixed $value): bool
    {
        return (is_string($value) || is_int($value)) && preg_match('/\A-?(?:0|[1-9]\d*)(?:\.\d{1,8})?\z/', (string) $value) === 1;
    }

    private function summaries(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'stable_key' => (string) ($row['key'] ?? ''),
            'kind' => 'estimate_row',
            'before' => ['quantity' => $row['quantity'] ?? null, 'total_cost' => $row['total_cost'] ?? null],
            'after' => null,
            'locator' => null,
        ], $rows);
    }

    /** @param array<string, mixed> $payload @param array<int, array<string, mixed>> $affected */
    private function technology(EstimateGenerationSession $session, array $payload, array $affected): array
    {
        $decisionKey = (string) ($payload['after']['decision_key'] ?? '');
        $optionId = (string) ($payload['after']['response'] ?? '');
        $analysis = is_array($session->analysis_payload) ? $session->analysis_payload : [];
        $recommendations = is_array($analysis['technology_recommendations'] ?? null) ? $analysis['technology_recommendations'] : [];
        $option = null;
        foreach ($recommendations as $recommendation) {
            if (! is_array($recommendation) || (string) ($recommendation['decision_key'] ?? '') !== $decisionKey) {
                continue;
            }
            foreach (is_array($recommendation['options'] ?? null) ? $recommendation['options'] : [] as $candidate) {
                if (is_array($candidate) && (string) ($candidate['id'] ?? $candidate['key'] ?? '') === $optionId) {
                    $option = $candidate;
                    break 2;
                }
            }
        }
        $afterRows = [];
        foreach (is_array($option['work_packages'] ?? null) ? $option['work_packages'] : [] as $package) {
            foreach (is_array($package) ? ($package['work_items'] ?? []) : [] as $row) {
                if (is_array($row)) {
                    $afterRows[] = $row;
                }
            }
        }
        if ($afterRows === []) {
            return ['state' => 'unknown', 'delta' => null, 'blockers' => ['technology_package_not_priced'], 'affected' => $this->summaries($affected)];
        }
        $beforeTotal = BigDecimal::zero();
        $afterTotal = BigDecimal::zero();
        foreach ($affected as $row) {
            if (($row['pricing_status'] ?? 'calculated') !== 'calculated' || ! $this->decimal($row['total_cost'] ?? null)) {
                return ['state' => 'unknown', 'delta' => null, 'blockers' => ['current_package_price_unavailable'], 'affected' => $this->summaries($affected)];
            }
            $beforeTotal = $beforeTotal->plus((string) $row['total_cost']);
        }
        foreach ($afterRows as $row) {
            if (($row['pricing_status'] ?? 'calculated') !== 'calculated' || ! $this->decimal($row['total_cost'] ?? null)) {
                return ['state' => 'unknown', 'delta' => null, 'blockers' => ['selected_package_price_unavailable'], 'affected' => $this->summaries($affected)];
            }
            $afterTotal = $afterTotal->plus((string) $row['total_cost']);
        }

        return ['state' => 'known', 'delta' => $afterTotal->minus($beforeTotal)->toScale(4, RoundingMode::HALF_UP)->__toString(), 'blockers' => [], 'affected' => $this->summaries($affected)];
    }
}
