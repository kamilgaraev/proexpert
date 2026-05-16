<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Services;

class MdmSimilarityService
{
    public function compare(string $entityType, array $left, array $right): array
    {
        $score = 0.0;
        $evidence = [];

        foreach (['inn', 'tax_number', 'email', 'phone', 'code', 'short_name'] as $field) {
            if (!empty($left[$field]) && !empty($right[$field]) && $left[$field] === $right[$field]) {
                $score += in_array($field, ['inn', 'tax_number'], true) ? 60 : 45;
                $evidence[$field] = 'exact';
            }
        }

        $nameScore = $this->nameSimilarity((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        if ($nameScore >= 0.75) {
            $score += $nameScore * 45;
            $evidence['name_similarity'] = round($nameScore, 3);
        }

        if (in_array($entityType, ['material', 'work_type', 'estimate_position'], true)
            && !empty($left['measurement_unit_id'])
            && $left['measurement_unit_id'] === ($right['measurement_unit_id'] ?? null)
        ) {
            $score += 40;
            $evidence['measurement_unit_id'] = 'exact';
        }

        return [
            'score' => min(100.0, round($score, 2)),
            'strategy' => $score >= 85 ? 'fuzzy' : 'none',
            'evidence' => $evidence,
        ];
    }

    public function normalizeBusinessName(string $value): string
    {
        $name = mb_strtolower(str_replace('ё', 'е', $value));
        $name = preg_replace('/["\'`.,«»()\-]+/u', ' ', $name) ?? $name;
        $name = preg_replace('/\b(ооо|ао|пао|зао|ип|общество|с|ограниченной|ответственностью)\b/u', ' ', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return trim($name);
    }

    private function nameSimilarity(string $left, string $right): float
    {
        $left = $this->normalizeBusinessName($left);
        $right = $this->normalizeBusinessName($right);

        if ($left === '' || $right === '') {
            return 0.0;
        }

        if ($left === $right) {
            return 1.0;
        }

        $max = max(strlen($left), strlen($right));

        return $max === 0 ? 0.0 : 1 - (levenshtein($left, $right) / $max);
    }
}
