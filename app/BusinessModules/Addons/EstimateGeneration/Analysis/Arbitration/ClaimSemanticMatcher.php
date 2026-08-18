<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

final class ClaimSemanticMatcher
{
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
