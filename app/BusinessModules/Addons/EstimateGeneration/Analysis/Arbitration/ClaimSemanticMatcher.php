<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use Throwable;

final class ClaimSemanticMatcher
{
    public function equivalent(ObservationClaim $left, ObservationClaim $right): bool
    {
        if ([$left->organizationId, $left->projectId, $left->sessionId, $left->sourceVersion]
            !== [$right->organizationId, $right->projectId, $right->sessionId, $right->sourceVersion]
            || (new VisualObjectIdentity)->normalizeEntityKey($left->entityKey)
                !== (new VisualObjectIdentity)->normalizeEntityKey($right->entityKey)
            || FactVocabulary::parameter($left->factType) !== FactVocabulary::parameter($right->factType)) {
            return false;
        }
        foreach (['floor_id', 'zone_id', 'room_id', 'entity_scope'] as $field) {
            if (($left->locator[$field] ?? null) !== ($right->locator[$field] ?? null)) {
                return false;
            }
        }
        if ($left->value['type'] === 'number' && $right->value['type'] === 'number') {
            foreach ([$left->value['data'], $right->value['data']] as $number) {
                if ((! is_string($number) && ! is_int($number) && ! is_float($number))
                    || (is_float($number) && ! is_finite($number))) {
                    return false;
                }
            }
            try {
                return FactVocabulary::measurementSignature((string) $left->value['data'], $left->unit)
                    === FactVocabulary::measurementSignature((string) $right->value['data'], $right->unit);
            } catch (Throwable) {
                return false;
            }
        }

        return $this->factSignatureForCanonical(['fact_type' => $left->factType, 'value' => $left->value, 'unit' => $left->unit])
            === $this->factSignatureForCanonical(['fact_type' => $right->factType, 'value' => $right->value, 'unit' => $right->unit]);
    }

    /** @param list<ObservationClaim> $claims @return list<list<ObservationClaim>> */
    public function groups(array $claims): array
    {
        $groups = [];
        foreach ($claims as $claim) {
            $key = $this->key($claim);
            $groups[$key][] = $claim;
        }
        foreach ($groups as &$group) {
            usort($group, static fn (ObservationClaim $left, ObservationClaim $right): int => $left->id <=> $right->id);
        }

        return array_values($groups);
    }

    public function key(ObservationClaim $claim): string
    {
        return $this->keyForCanonical([
            'entity_key' => $claim->entityKey,
            'fact_type' => $claim->factType,
            'value' => $claim->value,
            'unit' => $claim->unit,
        ]);
    }

    public function entityScope(ObservationClaim $claim): string
    {
        $scope = [];
        foreach (['floor_id', 'zone_id', 'room_id', 'entity_scope'] as $field) {
            if (isset($claim->locator[$field])) {
                $scope[$field] = $claim->locator[$field];
            }
        }

        return $scope === [] ? '' : '|scope:'.hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR));
    }

    public function keyForCanonical(array $canonical): string
    {
        return $this->concept((string) ($canonical['entity_key'] ?? '')).'|'.$this->factSignatureForCanonical($canonical);
    }

    public function factSignatureForCanonical(array $canonical): string
    {
        $value = is_array($canonical['value'] ?? null) ? $canonical['value'] : [];
        $data = $value['data'] ?? null;
        $encoded = is_scalar($data) || $data === null
            ? (string) $data
            : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $factType = $this->concept((string) ($canonical['fact_type'] ?? ''));
        if ($factType === 'level') {
            $factType = 'elevation';
        }
        $valueType = $this->concept((string) ($value['type'] ?? ''));
        $normalizedValue = $this->concept($encoded);
        if ($valueType === 'number' || ($factType === 'elevation' && $this->decimal($encoded) !== null)) {
            $valueType = 'number';
            $normalizedValue = $this->decimal($encoded) ?? $normalizedValue;
        }
        $unit = is_string($canonical['unit'] ?? null) ? $canonical['unit'] : null;
        if ($factType === 'elevation' && ($unit === null || trim($unit) === '')) {
            $unit = 'm';
        }

        return implode('|', [
            $factType,
            $valueType.':'.$normalizedValue,
            $this->unit($unit),
        ]);
    }

    private function decimal(string $value): ?string
    {
        $value = str_replace(["\u{00A0}", ' ', ','], ['', '', '.'], trim($value));
        $value = str_replace('±', '', $value);
        if (preg_match('/^([+-]?)([0-9]+)(?:\.([0-9]+))?$/D', $value, $match) !== 1) {
            return null;
        }
        $integer = ltrim($match[2], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($match[3] ?? '', '0');
        $sign = $match[1] === '-' && ($integer !== '0' || $fraction !== '') ? '-' : '';

        return $sign.$integer.($fraction === '' ? '' : '.'.$fraction);
    }

    private function value(ObservationClaim $claim): string
    {
        $value = $claim->value['data'];

        return is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function concept(string $value): string
    {
        $value = mb_strtolower(trim($value));
        if (str_contains($value, 'газобетон') || str_contains($value, 'ячеистого бетона')) {
            return 'газобетон';
        }
        if (str_contains($value, 'стен') && (str_contains($value, 'материал') || str_contains($value, 'несущ'))) {
            return 'материалстены';
        }
        $value = str_replace([
            'стеновой материал', 'материал стен', 'несущая стена',
            'газобетонный блок', 'блок из ячеистого бетона',
        ], [
            'материал стены', 'материал стены', 'материал стены',
            'газобетон', 'газобетон',
        ], $value);

        return preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? $value;
    }

    private function unit(?string $unit): string
    {
        return match (mb_strtolower(trim((string) $unit))) {
            'м²', 'м2', 'm²', 'm2' => 'm2',
            'м³', 'м3', 'm³', 'm3' => 'm3',
            'мм', 'mm' => 'mm',
            'см', 'cm' => 'cm',
            'м', 'm' => 'm',
            default => $this->concept((string) $unit),
        };
    }
}
