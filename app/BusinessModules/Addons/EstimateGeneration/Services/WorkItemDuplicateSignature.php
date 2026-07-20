<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

final readonly class WorkItemDuplicateSignature
{
    private function __construct(public string $value) {}

    /** @param array<string, mixed> $workItem */
    public static function fromWorkItem(array $workItem): ?self
    {
        $name = self::normalize((string) ($workItem['normative_search_text'] ?? $workItem['name'] ?? ''));
        $unit = self::normalize((string) ($workItem['unit'] ?? ''));
        $quantity = round((float) ($workItem['quantity'] ?? 0), 4);

        if ($name === '' || $unit === '' || $quantity <= 0) {
            return null;
        }

        $metadata = is_array($workItem['metadata'] ?? null) ? $workItem['metadata'] : [];
        $normativeIdentity = self::normalize((string) (
            $workItem['normative_rate_code']
            ?? $workItem['normative_search_key']
            ?? ''
        ));
        $technologicalIdentity = self::firstNormalizedIdentity([
            $metadata['material_scenario_work_key'] ?? null,
            $metadata['composition_work_key'] ?? null,
            $metadata['quantity_key'] ?? null,
            $workItem['quantity_formula'] ?? null,
        ]);

        return new self(hash('sha256', implode('|', [
            $name,
            $unit,
            (string) $quantity,
            $normativeIdentity,
            $technologicalIdentity,
        ])));
    }

    /** @param list<mixed> $values */
    private static function firstNormalizedIdentity(array $values): string
    {
        foreach ($values as $value) {
            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            $identity = self::normalize((string) $value);
            if ($identity !== '') {
                return $identity;
            }
        }

        return '';
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }
}
