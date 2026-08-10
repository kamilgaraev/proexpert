<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Presentation;

final class ProjectModelCorrectionHistoryPresenter
{
    /** @param list<\stdClass|array<string,mixed>> $rows @return array{current_value:array<string,mixed>|null,items:list<array<string,mixed>>,latest:array<string,mixed>|null} */
    public function present(array $rows): array
    {
        $items = [];
        $current = null;
        foreach ($rows as $index => $row) {
            $row = is_array($row) ? $row : get_object_vars($row);
            $payload = $this->payload($row['payload'] ?? null);
            $audit = is_array($payload['audit'] ?? null) ? $payload['audit'] : [];
            $next = is_array($audit['new_canonical_value'] ?? null)
                ? $audit['new_canonical_value']
                : (is_array($payload['canonical_value'] ?? null) ? $payload['canonical_value'] : null);
            $operation = in_array($audit['operation'] ?? null, ['apply', 'revert'], true)
                ? $audit['operation']
                : ($next === null ? null : 'apply');
            if ($operation === null || $next === null) {
                continue;
            }
            $current = $next;
            $lineage = $this->payload($row['evidence_lineage'] ?? null);
            if (array_is_list($lineage)) {
                $markers = array_values(array_filter(array_map(
                    static fn (mixed $item): ?string => is_array($item) && is_string($item['limitation_code'] ?? null)
                        ? $item['limitation_code']
                        : null,
                    $lineage,
                )));
            } else {
                $markers = [];
            }
            $association = in_array('historical_conflict_ambiguous', $markers, true)
                ? 'ambiguous'
                : (in_array('historical_conflict_unproven', $markers, true) ? 'unproven' : null);
            $targetConflictKey = is_string($row['target_conflict_key'] ?? null) && $association === null
                ? $row['target_conflict_key']
                : null;
            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'stable_key' => (string) ($row['stable_key'] ?? ''),
                'operation' => $operation,
                'previous_value' => is_array($audit['previous_canonical_value'] ?? null) ? $audit['previous_canonical_value'] : null,
                'value' => $next,
                'revert' => $operation === 'revert',
                'reverted' => false,
                'active' => false,
                'reverted_correction_id' => $audit['reverted_correction_id'] ?? null,
                'reason' => (string) ($row['reason'] ?? ''),
                'actor_id' => (int) ($row['actor_id'] ?? 0),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'target_conflict_key' => $targetConflictKey,
                'conflict_association' => $targetConflictKey !== null ? 'proven' : $association,
            ];
        }
        $indexById = [];
        foreach ($items as $index => $item) {
            $indexById[$item['id']] = $index;
        }
        foreach ($items as $index => $item) {
            if ($item['revert'] && is_int($item['reverted_correction_id']) && isset($indexById[$item['reverted_correction_id']])) {
                $items[$indexById[$item['reverted_correction_id']]]['reverted'] = true;
            }
            $items[$index]['active'] = $index === array_key_last($items) && ! $item['revert'];
        }
        $latest = $items === [] ? null : $items[array_key_last($items)];

        return ['current_value' => $current, 'items' => $items, 'latest' => $latest];
    }

    /** @return array<string,mixed> */
    private function payload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }
        $decoded = is_string($payload) ? json_decode($payload, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
