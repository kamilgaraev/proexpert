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
            $key = $this->concept($claim->entityKey).'|'.$this->concept($claim->factType).'|'.$this->concept($this->value($claim));
            $groups[$key][] = $claim;
        }
        foreach ($groups as &$group) {
            usort($group, static fn (ObservationClaim $left, ObservationClaim $right): int => $left->id <=> $right->id);
        }

        return array_values($groups);
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
}
