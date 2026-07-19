<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality;

use Throwable;

use function trans_message;

final class DraftReadinessInspector
{
    private const BLOCKING_CODES = [
        'geometry_scale_missing', 'geometry_scale_conflict', 'geometry_scale_unconfirmed',
        'evidence_missing', 'evidence_invalid', 'estimated_quantity_unconfirmed',
        'normative_missing', 'normative_rejected', 'unit_mismatch',
        'price_snapshot_missing', 'price_snapshot_unfinalized', 'duplicate_candidate',
        'blocking_review_unresolved', 'building_model_incomplete', 'cad_processing_failed',
        'required_scope_unresolved',
    ];

    public function __construct(
        private readonly DraftPackageCoverageInspector $packageCoverage = new DraftPackageCoverageInspector,
        private readonly DraftResidentialCompositionInspector $residentialComposition = new DraftResidentialCompositionInspector,
    ) {}

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
            $quantity = is_array($item['quantity_evidence'] ?? null) ? $item['quantity_evidence'] : [];
            $quantityReviewBlockers = array_values(array_filter(array_map(
                'strval',
                (array) ($quantity['review_blockers'] ?? $item['quantity_review_blockers'] ?? []),
            )));
            if (
                (($quantity['source'] ?? null) === 'estimated' || ($item['quantity_source'] ?? null) === 'estimated')
                && $quantityReviewBlockers !== []
            ) {
                $codes[] = 'estimated_quantity_unconfirmed';
            }
            $itemEvidence = $quantity['evidence_ids'] ?? null;
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
        $missingPackages = $this->packageCoverage->missingPackages($draft);
        $missingComposition = $this->residentialComposition->missingRequirements($draft);
        $unresolvedScope = $this->mergeMissingScope($missingPackages, $missingComposition);
        if ($unresolvedScope !== []) {
            $codes[] = 'required_scope_unresolved';
        }

        $codes = array_values(array_unique(array_filter($codes)));
        sort($codes, SORT_STRING);
        $warningCodes = array_values(array_unique([
            ...array_map('strval', (array) ($draft['quality_summary']['warning_codes'] ?? [])),
            ...$this->compositionAdviceWarningCodes($draft),
        ]));
        sort($warningCodes, SORT_STRING);

        return new DraftReadinessInspection(
            array_map(
                fn (string $code): array => $this->issue(
                    $code,
                    $code === 'required_scope_unresolved' ? ['packages' => $unresolvedScope] : [],
                ),
                $codes,
            ),
            array_map($this->issue(...), $warningCodes),
            array_merge(
                array_fill_keys(array_map(static fn (string $code): string => 'gate_'.$code, self::BLOCKING_CODES), 0),
                array_fill_keys(array_map(static fn (string $code): string => 'warning_'.$code, $warningCodes), 1),
                array_fill_keys(array_map(static fn (string $code): string => 'gate_'.$code, $codes), 1),
            ),
        );
    }

    private function mergeMissingScope(array $missingPackages, array $missingComposition): array
    {
        $merged = [];
        foreach ([...$missingPackages, ...$missingComposition] as $package) {
            if (! is_array($package) || trim((string) ($package['key'] ?? '')) === '') {
                continue;
            }
            $key = (string) $package['key'];
            $current = $merged[$key] ?? ['key' => $key, 'title' => (string) ($package['title'] ?? $key)];
            if (is_array($package['missing_items'] ?? null)) {
                $current['missing_items'] = array_values(array_unique([
                    ...(is_array($current['missing_items'] ?? null) ? $current['missing_items'] : []),
                    ...array_map('strval', $package['missing_items']),
                ]));
            }
            $merged[$key] = $current;
        }

        return array_values($merged);
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

    /** @return list<string> */
    private function compositionAdviceWarningCodes(array $draft): array
    {
        $status = (string) ($draft['package_plan']['work_composition_advice']['status'] ?? '');
        $codes = in_array($status, ['invalid', 'unavailable'], true)
            ? ['work_composition_ai_'.$status]
            : [];

        foreach ($this->workItems($draft) as $item) {
            $coverage = is_array($item['metadata']['composition_coverage'] ?? null)
                ? $item['metadata']['composition_coverage']
                : [];
            if (in_array($coverage['ai_status'] ?? null, ['needs_data', 'not_applicable'], true)) {
                $codes[] = 'work_composition_ai_conflict';
            }
        }

        return array_values(array_unique($codes));
    }

    private function issue(string $code, array $details = []): array
    {
        $messageKey = 'estimate_generation.readiness_'.$code;
        $message = $messageKey;
        try {
            $message = trans_message($messageKey);
        } catch (Throwable) {
        }

        return [
            'code' => $code,
            'message_key' => $messageKey,
            'message' => $message,
            ...($details !== [] ? ['details' => $details] : []),
        ];
    }
}
