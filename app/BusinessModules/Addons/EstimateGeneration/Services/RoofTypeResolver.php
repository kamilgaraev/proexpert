<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

final class RoofTypeResolver
{
    public function resolve(array $analysis): ?string
    {
        $object = is_array($analysis['object'] ?? null) ? $analysis['object'] : [];
        $documentContext = is_array($analysis['document_context'] ?? null) ? $analysis['document_context'] : [];
        $factsSummary = is_array($documentContext['facts_summary'] ?? null) ? $documentContext['facts_summary'] : [];
        $detectedStructure = is_array($analysis['detected_structure'] ?? null) ? $analysis['detected_structure'] : [];

        $structured = $this->uniqueTypes([
            $analysis['roof_type'] ?? null,
            $object['roof_type'] ?? null,
            $documentContext['roof_type'] ?? null,
            $factsSummary['roof_type'] ?? null,
            $detectedStructure['roof_type'] ?? null,
            ...$this->takeoffRoofTypes($documentContext),
        ]);
        if (count($structured) === 1) {
            return $structured[0];
        }
        if (count($structured) > 1) {
            return null;
        }

        $text = mb_strtolower(implode(' ', $this->textFragments($analysis, $documentContext)));
        $types = [];
        if (preg_match('/(?:плоск\p{L}*\s+кровл\p{L}*|flat\s+roof)/u', $text) === 1) {
            $types[] = 'flat';
        }
        if (preg_match('/(?:(?:скатн|двускатн|односкатн|вальмов)\p{L}*\s+кровл\p{L}*|pitched\s+roof|gable\s+roof|hip\s+roof)/u', $text) === 1) {
            $types[] = 'pitched';
        }

        return count(array_unique($types)) === 1 ? $types[0] : null;
    }

    /** @param array<int, mixed> $values @return list<string> */
    private function uniqueTypes(array $values): array
    {
        $types = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $normalized = match (mb_strtolower(trim($value))) {
                'flat', 'плоская', 'плоский' => 'flat',
                'pitched', 'gable', 'hip', 'скатная', 'скатный', 'двускатная', 'вальмовая' => 'pitched',
                default => null,
            };
            if ($normalized !== null) {
                $types[$normalized] = true;
            }
        }

        return array_keys($types);
    }

    /** @return list<string> */
    private function textFragments(array $analysis, array $documentContext): array
    {
        $object = is_array($analysis['object'] ?? null) ? $analysis['object'] : [];
        $values = [
            $object['description'] ?? null,
            $documentContext['context_text'] ?? null,
            $documentContext['summary'] ?? null,
        ];
        foreach ((array) ($documentContext['facts'] ?? []) as $fact) {
            if (! is_array($fact)) {
                continue;
            }

            foreach (['label', 'value_text', 'name', 'title'] as $key) {
                $values[] = $fact[$key] ?? null;
            }
        }

        return array_values(array_filter($values, static fn (mixed $value): bool => is_string($value) && trim($value) !== ''));
    }

    /** @return list<string> */
    private function takeoffRoofTypes(array $documentContext): array
    {
        $types = [];
        foreach ((array) ($documentContext['quantity_takeoffs'] ?? []) as $takeoff) {
            if (! is_array($takeoff) || ! is_numeric($takeoff['quantity'] ?? null) || (float) $takeoff['quantity'] <= 0) {
                continue;
            }

            $normalized = is_array($takeoff['normalized_payload'] ?? null) ? $takeoff['normalized_payload'] : [];
            if (($takeoff['review_required'] ?? $normalized['review_required'] ?? false) === true) {
                continue;
            }

            $quantityKey = (string) ($takeoff['quantity_key'] ?? $normalized['quantity_key'] ?? '');
            if ($quantityKey === 'roof.flat_area') {
                $types[] = 'flat';
            } elseif ($quantityKey === 'roof.rafters') {
                $types[] = 'pitched';
            }
        }

        return $types;
    }
}
