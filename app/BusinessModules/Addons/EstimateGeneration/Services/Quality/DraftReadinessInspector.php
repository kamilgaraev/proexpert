<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality;

final class DraftReadinessInspector
{
    private const BLOCKING_CODES = [
        'geometry_scale_missing', 'geometry_scale_conflict', 'geometry_scale_unconfirmed',
        'evidence_missing', 'evidence_invalid', 'estimated_quantity_unconfirmed',
        'normative_missing', 'normative_rejected', 'unit_mismatch',
        'price_snapshot_missing', 'price_snapshot_unfinalized', 'duplicate_candidate',
        'blocking_review_unresolved', 'building_model_incomplete', 'cad_processing_failed',
    ];

    public function inspect(array $draft): DraftReadinessInspection
    {
        $codes = [];
        $model = is_array($draft['building_model'] ?? null) ? $draft['building_model'] : [];
        $scale = $model['scale_status'] ?? null;
        $codes[] = match ($scale) {
            'confirmed' => null,
            'conflict' => 'geometry_scale_conflict',
            'estimated' => 'geometry_scale_unconfirmed',
            default => 'geometry_scale_missing',
        };
        $evidence = $model['evidence_ids'] ?? null;
        if (! is_array($evidence) || $evidence === []) {
            $codes[] = 'evidence_missing';
        } elseif (array_filter($evidence, static fn (mixed $id): bool => ! is_int($id) || $id <= 0) !== []) {
            $codes[] = 'evidence_invalid';
        }
        if (($model['metrics']['complete'] ?? false) !== true) {
            $codes[] = 'building_model_incomplete';
        }
        if (($model['cad_status'] ?? null) === 'failed') {
            $codes[] = 'cad_processing_failed';
        }

        foreach ($this->workItems($draft) as $item) {
            $quantity = is_array($item['quantity'] ?? null) ? $item['quantity'] : [];
            if (($quantity['source'] ?? null) === 'estimated' || ($item['quantity_source'] ?? null) === 'estimated') {
                $codes[] = 'estimated_quantity_unconfirmed';
            }
            $itemEvidence = $quantity['evidence_ids'] ?? $item['evidence_ids'] ?? null;
            if (! is_array($itemEvidence) || $itemEvidence === []) {
                $codes[] = 'evidence_missing';
            }
            $match = is_array($item['normative_match'] ?? null) ? $item['normative_match'] : [];
            if (! in_array($match['status'] ?? null, ['matched', 'accepted'], true)) {
                $codes[] = 'normative_missing';
            }
            if (($match['decision']['status'] ?? null) === 'rejected') {
                $codes[] = 'normative_rejected';
            }
            $warnings = array_merge((array) ($match['warnings'] ?? []), (array) ($match['decision']['warnings'] ?? []));
            if (in_array('unit_mismatch', $warnings, true)) {
                $codes[] = 'unit_mismatch';
            }
            if (! is_array($item['price_snapshot'] ?? null) || $item['price_snapshot'] === []) {
                $codes[] = 'price_snapshot_missing';
            }
            if (! is_string($item['pricing_finalized_at'] ?? null) || trim($item['pricing_finalized_at']) === '') {
                $codes[] = 'price_snapshot_unfinalized';
            }
        }
        if ((int) ($draft['quality_summary']['duplicate_work_items'] ?? 0) > 0) {
            $codes[] = 'duplicate_candidate';
        }
        if ((int) ($draft['quality_summary']['review_items']['blocking'] ?? 0) > 0) {
            $codes[] = 'blocking_review_unresolved';
        }

        $codes = array_values(array_unique(array_filter($codes)));
        sort($codes, SORT_STRING);
        $warningCodes = array_values(array_unique(array_map('strval', (array) ($draft['quality_summary']['warning_codes'] ?? []))));
        sort($warningCodes, SORT_STRING);

        return new DraftReadinessInspection(
            array_map($this->issue(...), $codes),
            array_map($this->issue(...), $warningCodes),
            array_merge(
                array_fill_keys(array_map(static fn (string $code): string => 'gate_'.$code, self::BLOCKING_CODES), 0),
                array_fill_keys(array_map(static fn (string $code): string => 'warning_'.$code, $warningCodes), 1),
                array_fill_keys(array_map(static fn (string $code): string => 'gate_'.$code, $codes), 1),
            ),
        );
    }

    private function workItems(array $draft): array
    {
        $items = [];
        foreach ((array) ($draft['local_estimates'] ?? []) as $estimate) {
            foreach ((array) ($estimate['sections'] ?? []) as $section) {
                foreach ((array) ($section['work_items'] ?? []) as $item) {
                    if (is_array($item) && ($item['item_type'] ?? 'priced_work') === 'priced_work') {
                        $items[] = $item;
                    }
                }
            }
        }

        return $items;
    }

    private function issue(string $code): array
    {
        return ['code' => $code, 'message_key' => 'estimate_generation.readiness_'.$code, 'message' => 'estimate_generation.readiness_'.$code];
    }
}
