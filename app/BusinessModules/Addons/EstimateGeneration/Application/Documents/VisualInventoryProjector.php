<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

final class VisualInventoryProjector
{
    private const FACT_TYPES = [
        'sanitary_fixture',
        'kitchen_fixture',
        'equipment',
        'furniture',
        'unknown_fixture',
    ];

    /**
     * @param  array<string, array<string, mixed>>  $observers
     * @param  array<string, mixed>|null  $arbitration
     * @param  array{document_id:int,page_id:int,page_number:int,source_version:string}  $scope
     * @return array{items:list<array<string,mixed>>,quarantined_items:list<array<string,mixed>>}
     */
    public function project(array $observers, ?array $arbitration, array $scope): array
    {
        $decisions = $this->decisions($arbitration);
        $conditional = $this->hasConditionalNote($observers, $scope);
        $items = [];
        $quarantined = [];

        foreach ($observers as $role => $observer) {
            if (! is_string($role) || ! is_array($observer) || ! $this->sameScope($observer['source'] ?? null, $scope)) {
                $quarantined[] = ['section' => 'visual_inventory', 'reason_code' => 'observer_scope_invalid'];

                continue;
            }
            $shortRole = str_replace('observer_', '', $role);
            $evidence = $this->evidence($observer['evidence'] ?? null, $scope);
            foreach (is_array($observer['claims'] ?? null) ? $observer['claims'] : [] as $index => $claim) {
                if (! is_array($claim)
                    || ! is_string($claim['entityKey'] ?? null)
                    || ! is_string($claim['factType'] ?? null)
                    || ! in_array($claim['factType'], self::FACT_TYPES, true)) {
                    if (! is_array($claim) || ! is_string($claim['factType'] ?? null)) {
                        $quarantined[] = ['section' => 'visual_inventory', 'index' => is_int($index) ? $index : null, 'reason_code' => 'visual_object_invalid'];
                    }

                    continue;
                }
                $value = $claim['value']['data'] ?? null;
                $evidenceRef = $claim['evidenceRef'] ?? null;
                if (! is_string($value) || trim($value) === '' || ! is_string($evidenceRef) || ! isset($evidence[$evidenceRef])) {
                    $quarantined[] = ['section' => 'visual_inventory', 'index' => is_int($index) ? $index : null, 'reason_code' => 'visual_object_evidence_invalid'];

                    continue;
                }
                $claimId = $shortRole.':'.((int) $index + 1);
                $decision = $decisions[$claimId] ?? null;
                $category = $claim['factType'];
                $scopeValue = $this->estimateScope($category, $value, $conditional);
                $item = [
                    'key' => mb_substr($claim['entityKey'], 0, 120),
                    'label' => mb_substr(trim($value), 0, 160),
                    'category' => $category,
                    'object_type' => $this->objectType($value),
                    'quantity' => $this->quantity($value),
                    'quantity_uncertain' => $this->quantity($value) === null,
                    'room_key' => $this->roomKey($claim['entityKey']),
                    'scope' => $scopeValue,
                    'evidence_locator' => $evidence[$evidenceRef],
                    'arbitration' => [
                        'status' => is_string($decision['status'] ?? null) ? $decision['status'] : 'conditional',
                        'reason_code' => is_string($decision['reason_code'] ?? null)
                            ? $decision['reason_code']
                            : 'minority_evidence_preserved',
                    ],
                    'lineage' => [
                        'claim_id' => $claimId,
                        'supporting_claim_ids' => array_values(array_filter(
                            is_array($decision['supporting_claim_ids'] ?? null) ? $decision['supporting_claim_ids'] : [$claimId],
                            'is_string',
                        )),
                        'evidence_refs' => array_values(array_filter(
                            is_array($decision['evidence_refs'] ?? null) ? $decision['evidence_refs'] : [$shortRole.':'.$evidenceRef],
                            'is_string',
                        )),
                    ],
                ];
                $items[$this->identity($item)] = isset($items[$this->identity($item)])
                    ? $this->merge($items[$this->identity($item)], $item)
                    : $item;
            }
        }

        return ['items' => array_values($items), 'quarantined_items' => $quarantined];
    }

    private function decisions(?array $arbitration): array
    {
        $result = [];
        foreach (is_array($arbitration['decisions'] ?? null) ? $arbitration['decisions'] : [] as $decision) {
            if (is_array($decision) && is_string($decision['claim_id'] ?? null)) {
                $result[$decision['claim_id']] = $decision;
            }
        }

        return $result;
    }

    private function evidence(mixed $items, array $scope): array
    {
        $result = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $locator = is_array($item) ? ($item['locator'] ?? null) : null;
            if (is_string($item['key'] ?? null) && is_array($locator)
                && ($locator['page_id'] ?? null) === $scope['page_id']
                && ($locator['page_number'] ?? null) === $scope['page_number']
                && ($locator['source_version'] ?? null) === $scope['source_version']) {
                $result[$item['key']] = $locator;
            }
        }

        return $result;
    }

    private function sameScope(mixed $source, array $scope): bool
    {
        return is_array($source)
            && ($source['document_id'] ?? null) === $scope['document_id']
            && ($source['page_id'] ?? null) === $scope['page_id']
            && ($source['page_number'] ?? null) === $scope['page_number']
            && ($source['source_version'] ?? null) === $scope['source_version'];
    }

    private function hasConditionalNote(array $observers, array $scope): bool
    {
        foreach ($observers as $observer) {
            if (! is_array($observer) || ! $this->sameScope($observer['source'] ?? null, $scope)) {
                continue;
            }
            foreach (is_array($observer['claims'] ?? null) ? $observer['claims'] : [] as $claim) {
                $value = is_array($claim) ? ($claim['value']['data'] ?? null) : null;
                if (($claim['factType'] ?? null) === 'note' && is_string($value)
                    && preg_match('/условн|for reference|indicative/iu', $value) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private function estimateScope(string $category, string $value, bool $conditional): string
    {
        if (in_array($category, ['furniture', 'equipment'], true)) {
            return $conditional ? 'excluded_by_document_note' : 'contextual_only';
        }
        if ($category === 'kitchen_fixture' && preg_match('/шкаф|мебел|холодиль|посудомо|духов|cabin|fridge/iu', $value) === 1) {
            return $conditional ? 'excluded_by_document_note' : 'contextual_only';
        }

        return 'requires_confirmation';
    }

    private function objectType(string $value): string
    {
        return match (true) {
            preg_match('/унитаз|toilet/iu', $value) === 1 => 'toilet',
            preg_match('/умываль|раковин|washbasin/iu', $value) === 1 => 'washbasin',
            preg_match('/мойк|kitchen sink/iu', $value) === 1 => 'kitchen_sink',
            preg_match('/ванн|bath/iu', $value) === 1 => 'bath',
            preg_match('/душ|shower/iu', $value) === 1 => 'shower',
            preg_match('/кроват|bed/iu', $value) === 1 => 'bed',
            preg_match('/стол|table/iu', $value) === 1 => 'table',
            preg_match('/стул|chair/iu', $value) === 1 => 'chair',
            preg_match('/диван|sofa/iu', $value) === 1 => 'sofa',
            preg_match('/плит|cooktop|stove/iu', $value) === 1 => 'stove',
            default => 'unknown',
        };
    }

    private function quantity(string $value): ?int
    {
        return preg_match('/(?:^|\s)([1-9][0-9]?)(?:\s|$)/u', $value, $matches) === 1 ? (int) $matches[1] : null;
    }

    private function roomKey(string $entityKey): ?string
    {
        return preg_match('/\A(room[.:_-][a-z0-9_-]+)/i', $entityKey, $matches) === 1 ? $matches[1] : null;
    }

    private function identity(array $item): string
    {
        return hash('sha256', implode('|', [$item['category'], $item['object_type'], $item['room_key'] ?? '', mb_strtolower($item['label'])]));
    }

    private function merge(array $left, array $right): array
    {
        $left['lineage']['supporting_claim_ids'] = array_values(array_unique([
            ...$left['lineage']['supporting_claim_ids'],
            ...$right['lineage']['supporting_claim_ids'],
        ]));
        $left['lineage']['evidence_refs'] = array_values(array_unique([
            ...$left['lineage']['evidence_refs'],
            ...$right['lineage']['evidence_refs'],
        ]));

        return $left;
    }
}
