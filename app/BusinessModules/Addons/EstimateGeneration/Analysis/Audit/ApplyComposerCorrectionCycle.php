<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerCorrectionInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposerCorrection;

final readonly class ApplyComposerCorrectionCycle
{
    public function __construct(
        private RunEstimateAudit $auditor,
        private RunEstimateComposerCorrection $corrector,
    ) {}

    /** @return array{draft:array<string,mixed>,audit:array<string,mixed>} */
    public function apply(EstimateAuditInput $root): array
    {
        $draft = $root->draft;
        $history = [];
        $correctionCycles = 0;
        $final = ['accepted' => false, 'findings' => []];
        for ($cycle = 0; $cycle <= 2; $cycle++) {
            $input = $root->withDraft($draft, $cycle);
            $final = $this->auditor->run($input);
            $history[] = [
                'cycle' => $cycle,
                'input_fingerprint' => $input->fingerprint(),
                'accepted' => $final['accepted'],
                'findings_count' => count($final['findings']),
            ];
            if ($final['accepted'] || $cycle === 2) {
                break;
            }
            [$corrected, $applied] = $this->applyDeterministicCorrections($draft, $final['findings']);
            $remaining = array_values(array_filter(
                $final['findings'],
                static fn (array $finding): bool => ($finding['correction']['operation'] ?? null) !== 'remove_exact_duplicate',
            ));
            if ($remaining !== []) {
                $corrections = $this->corrector->run(new EstimateComposerCorrectionInput($input, $remaining));
                [$corrected, $composerApplied] = $this->applyComposerCorrections($corrected, $corrections, $input->derivedQuantities);
                $applied = $applied || $composerApplied;
            }
            if (! $applied) {
                break;
            }
            $draft = $this->refreshArtifactHash($corrected);
            $correctionCycles++;
        }
        $reviewItems = $final['accepted'] ? [] : array_values(array_map(
            static fn (array $finding): array => [
                'finding_id' => $finding['finding_id'],
                'type' => $finding['type'],
                'severity' => $finding['severity'],
                'item_key' => $finding['item_key'],
                'reason' => $finding['reason'],
                'impact' => $finding['impact'],
                'recommendation' => $finding['recommendation'],
                'source_fact_ids' => $finding['source_fact_ids'],
                'source_locator' => $finding['source_locator'],
            ],
            array_filter($final['findings'], static fn (array $finding): bool => $finding['severity'] === 'material'),
        ));
        $draft['audit_review_items'] = array_slice($reviewItems, 0, 1000);
        $audit = [
            'schema_version' => 1,
            'snapshot_token' => $root->snapshotToken,
            'status' => $final['accepted'] ? 'accepted' : 'review_required',
            'correction_cycles' => $correctionCycles,
            'findings' => $final['findings'],
            'history' => $history,
        ];
        $draft['independent_audit'] = $audit;

        return ['draft' => $draft, 'audit' => $audit];
    }

    /** @param list<array<string,mixed>> $corrections @param list<array<string,mixed>> $derivedQuantities @return array{array<string,mixed>,bool} */
    private function applyComposerCorrections(array $draft, array $corrections, array $derivedQuantities): array
    {
        $quantities = [];
        foreach ($derivedQuantities as $quantity) {
            if (is_array($quantity) && is_string($quantity['id'] ?? null)
                && is_string($quantity['value'] ?? null) && is_string($quantity['unit'] ?? null)) {
                $quantities[$quantity['id']] = $quantity;
            }
        }
        $applied = false;
        foreach ($corrections as $correction) {
            $quantity = $quantities[$correction['derived_quantity_id']] ?? null;
            if ($quantity === null) {
                continue;
            }
            if ($correction['operation'] === 'add_work') {
                if ($this->findItem($draft, $correction['work_key']) !== null) {
                    continue;
                }
                $estimateIndex = count($draft['local_estimates'] ?? []);
                $draft['local_estimates'][$estimateIndex] = [
                    'key' => 'ai-audit-corrections',
                    'title' => $correction['name'],
                    'scope_type' => 'supplementary',
                    'source_refs' => array_map(static fn (string $factId): array => ['fact_id' => $factId], $correction['source_fact_ids']),
                    'sections' => [[
                        'key' => 'ai-audit-corrections',
                        'title' => $correction['name'],
                        'work_items' => [[
                            'key' => $correction['work_key'],
                            'name' => $correction['name'],
                            'item_type' => 'priced_work',
                            'unit' => $quantity['unit'],
                            'quantity' => $quantity['value'],
                            'quantity_formula' => $quantity['id'],
                            'source_refs' => array_map(static fn (string $factId): array => ['fact_id' => $factId], $correction['source_fact_ids']),
                            'metadata' => ['audit_correction' => ['finding_id' => $correction['finding_id']]],
                        ]],
                    ]],
                ];
                $applied = true;

                continue;
            }
            $location = $this->findItem($draft, $correction['target_item_key']);
            if ($location === null) {
                continue;
            }
            [$estimateIndex, $sectionIndex, $itemIndex] = $location;
            $item = &$draft['local_estimates'][$estimateIndex]['sections'][$sectionIndex]['work_items'][$itemIndex];
            if (! hash_equals($correction['expected_target_fingerprint'], self::itemFingerprint($item))) {
                unset($item);

                continue;
            }
            if ($correction['operation'] === 'replace_quantity') {
                $item['quantity'] = $quantity['value'];
                $item['quantity_formula'] = $quantity['id'];
            } else {
                $item['unit'] = $quantity['unit'];
            }
            $item['metadata']['audit_correction'] = ['finding_id' => $correction['finding_id']];
            unset($item);
            $applied = true;
        }

        return [$draft, $applied];
    }

    /** @return array{int,int,int}|null */
    private function findItem(array $draft, string $key): ?array
    {
        foreach ($draft['local_estimates'] ?? [] as $estimateIndex => $estimate) {
            foreach (is_array($estimate) ? ($estimate['sections'] ?? []) : [] as $sectionIndex => $section) {
                foreach (is_array($section) ? ($section['work_items'] ?? []) : [] as $itemIndex => $item) {
                    if (is_array($item) && ($item['key'] ?? null) === $key) {
                        return [$estimateIndex, $sectionIndex, $itemIndex];
                    }
                }
            }
        }

        return null;
    }

    /** @param array<string,mixed> $item */
    public static function itemFingerprint(array $item): string
    {
        unset($item['key'], $item['metadata']['stage6_provenance']['artifact']['artifact_hash']);

        return hash('sha256', json_encode(self::canonicalize($item), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param list<array<string,mixed>> $findings @return array{array<string,mixed>,bool} */
    private function applyDeterministicCorrections(array $draft, array $findings): array
    {
        $applied = false;
        foreach ($findings as $finding) {
            $correction = $finding['correction'];
            if (($correction['operation'] ?? null) !== 'remove_exact_duplicate') {
                continue;
            }
            foreach ($draft['local_estimates'] ?? [] as $localIndex => $localEstimate) {
                foreach (is_array($localEstimate) ? ($localEstimate['sections'] ?? []) : [] as $sectionIndex => $section) {
                    $items = is_array($section) && is_array($section['work_items'] ?? null) ? $section['work_items'] : [];
                    $targetIndex = $this->itemIndex($items, $correction['target_item_key']);
                    $retainedIndex = $this->itemIndex($items, $correction['retained_item_key']);
                    if ($targetIndex === null || $retainedIndex === null || $targetIndex === $retainedIndex) {
                        continue;
                    }
                    $target = $items[$targetIndex];
                    $retained = $items[$retainedIndex];
                    if (! is_array($target) || ! is_array($retained)
                        || ! hash_equals($correction['expected_target_fingerprint'], self::itemFingerprint($target))
                        || ! hash_equals($correction['expected_retained_fingerprint'], self::itemFingerprint($retained))
                        || ! hash_equals(self::itemFingerprint($target), self::itemFingerprint($retained))) {
                        continue;
                    }
                    array_splice($draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'], $targetIndex, 1);
                    $applied = true;
                    break 2;
                }
            }
        }

        return [$draft, $applied];
    }

    private function itemIndex(array $items, string $key): ?int
    {
        foreach ($items as $index => $item) {
            if (is_array($item) && ($item['key'] ?? null) === $key) {
                return $index;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $draft @return array<string,mixed> */
    private function refreshArtifactHash(array $draft): array
    {
        unset($draft['artifact_hash']);
        $this->attachArtifactHash($draft, null);
        $draft['artifact_hash'] = hash('sha256', json_encode(
            self::canonicalize($draft),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
        $this->attachArtifactHash($draft, $draft['artifact_hash']);

        return $draft;
    }

    private function attachArtifactHash(array &$draft, ?string $artifactHash): void
    {
        foreach ($draft['local_estimates'] ?? [] as $localIndex => $localEstimate) {
            foreach (is_array($localEstimate) ? ($localEstimate['sections'] ?? []) : [] as $sectionIndex => $section) {
                foreach (is_array($section) ? ($section['work_items'] ?? []) : [] as $rowIndex => $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $path = &$draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$rowIndex];
                    if ($artifactHash === null) {
                        unset($path['metadata']['stage6_provenance']['artifact']['artifact_hash']);
                    } elseif (isset($path['metadata']['stage6_provenance']['artifact'])) {
                        $path['metadata']['stage6_provenance']['artifact']['artifact_hash'] = $artifactHash;
                    }
                    unset($path);
                }
            }
        }
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
