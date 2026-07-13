<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Presentation;

final class QuantityFormulaInputsPresenter
{
    private const DIRECT_OPERAND_ROLES = [
        'amount',
        'area',
        'depth',
        'gross_wall_area',
        'height',
        'length',
        'side_multiplier',
        'width',
    ];

    private const MAX_ITEMS = 5000;

    private const MAX_OPERANDS = 10000;

    /** @param list<int>|null $activeEvidenceIds @return array{items: list<array<string, mixed>>} */
    public function present(mixed $value, ?array $activeEvidenceIds = null): array
    {
        if (! is_array($value) || ! is_array($value['items'] ?? null) || ! array_is_list($value['items'])) {
            return ['items' => []];
        }

        $items = [];
        foreach (array_slice($value['items'], 0, self::MAX_ITEMS) as $item) {
            $projected = $this->item($item);
            if ($projected !== null) {
                if ($activeEvidenceIds !== null) {
                    $projected = $this->activeEvidence($projected, $activeEvidenceIds);
                }
                $items[] = $projected;
            }
        }

        return ['items' => $items];
    }

    /** @return array<string, mixed>|null */
    private function item(mixed $value): ?array
    {
        if (! is_array($value)
            || ! is_string($value['identity'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $value['identity']) !== 1
            || ! $this->decimal($value['amount'] ?? null)
        ) {
            return null;
        }
        $evidenceIds = $this->evidenceIds($value['evidence_ids'] ?? null);
        $versions = $this->versions($value['provenance_versions'] ?? null);
        if ($evidenceIds === null || $versions === null || ! is_array($value['named_operands'] ?? null)) {
            return null;
        }
        $operands = $this->namedOperands($value['named_operands']);

        return [
            'identity' => $value['identity'],
            'amount' => $value['amount'],
            'evidence_ids' => $evidenceIds,
            'provenance_versions' => $versions,
            'operands' => array_slice($operands, 0, self::MAX_OPERANDS),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function namedOperands(array $value): array
    {
        $result = [];
        foreach (self::DIRECT_OPERAND_ROLES as $role) {
            $operand = $value[$role] ?? null;
            if (! is_array($operand)) {
                continue;
            }
            $projected = $this->operand($operand, $role, $role);
            if ($projected !== null) {
                $result[] = $projected;
            }
        }

        $openings = $value['openings'] ?? null;
        if (! is_array($openings)) {
            return $result;
        }
        foreach (array_slice($openings, 0, self::MAX_OPERANDS, true) as $id => $operand) {
            if (! is_string($id)
                || preg_match('/\A[a-z0-9][a-z0-9_.-]{0,79}\z/', $id) !== 1
                || ! is_array($operand)
            ) {
                continue;
            }
            $projected = $this->operand($operand, 'openings.'.$id, 'opening_area');
            if ($projected !== null) {
                $result[] = $projected;
            }
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function operand(array $value, string $name, string $expectedRole): ?array
    {
        $evidenceIds = $this->evidenceIds($value['evidence_ids'] ?? null);
        if ($name === ''
            || preg_match('/\A[a-z][a-z0-9_.-]{0,159}\z/', $name) !== 1
            || ($value['role'] ?? null) !== $expectedRole
            || ! $this->decimal($value['value'] ?? null)
            || ! is_string($value['unit'] ?? null)
            || ! in_array($value['unit'], ['m', 'm2', 'm3', 'count', 'pcs'], true)
            || $evidenceIds === null
            || ! $this->safeVersion($value['context_id'] ?? null)
            || ! $this->safeVersion($value['provenance_version'] ?? null)
        ) {
            return null;
        }

        return [
            'name' => $name,
            'value' => $value['value'],
            'unit' => $value['unit'],
            'evidence_ids' => $evidenceIds,
            'context_id' => $value['context_id'],
            'provenance_version' => $value['provenance_version'],
        ];
    }

    /** @return list<int>|null */
    private function evidenceIds(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }
        $ids = [];
        foreach ($value as $id) {
            if ((is_int($id) && $id > 0) || (is_string($id) && ctype_digit($id) && (int) $id > 0)) {
                $ids[] = (int) $id;
            } else {
                return null;
            }
        }
        if (count($ids) !== count(array_unique($ids))) {
            return null;
        }

        return $ids;
    }

    /** @return list<string>|null */
    private function versions(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }
        foreach ($value as $version) {
            if (! $this->safeVersion($version)) {
                return null;
            }
        }

        return $value;
    }

    private function decimal(mixed $value): bool
    {
        return is_string($value)
            && strlen($value) <= 64
            && preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/', $value) === 1;
    }

    private function safeVersion(mixed $value): bool
    {
        return is_string($value)
            && $value !== ''
            && mb_check_encoding($value, 'UTF-8')
            && mb_strlen($value, 'UTF-8') <= 80
            && preg_match('/\A[\p{L}\p{N}][\p{L}\p{N}:._-]*\z/u', $value) === 1;
    }

    /** @param array<string, mixed> $item @param list<int> $activeEvidenceIds @return array<string, mixed> */
    private function activeEvidence(array $item, array $activeEvidenceIds): array
    {
        $active = array_fill_keys($activeEvidenceIds, true);
        $item['evidence_ids'] = array_values(array_filter(
            $item['evidence_ids'],
            static fn (int $id): bool => isset($active[$id]),
        ));
        $item['operands'] = array_map(static function (array $operand) use ($active): array {
            $operand['evidence_ids'] = array_values(array_filter(
                $operand['evidence_ids'],
                static fn (int $id): bool => isset($active[$id]),
            ));

            return $operand;
        }, $item['operands']);

        return $item;
    }
}
