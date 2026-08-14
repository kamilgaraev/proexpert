<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit;

final readonly class ApplyComposerCorrectionCycle
{
    public function __construct(private RunEstimateAudit $auditor) {}

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
