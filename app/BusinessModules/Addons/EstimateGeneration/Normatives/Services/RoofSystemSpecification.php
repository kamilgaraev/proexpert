<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use App\BusinessModules\Addons\EstimateGeneration\Quantities\RoofSystemScope;

final class RoofSystemSpecification
{
    public static function searchText(?string $system): ?string
    {
        return match ($system) {
            'concrete_tile' => 'Устройство кровли из цементно-песчаной (бетонной) черепицы',
            'metal_tile' => 'Устройство кровли из металлочерепицы',
            'flexible_shingle' => 'Устройство кровли из гибкой битумной черепицы',
            'standing_seam' => 'Устройство фальцевой кровли из листовой стали',
            default => null,
        };
    }

    public static function specialize(array $item): array
    {
        if (! RoofSystemScope::isRoofWork($item)) {
            return $item;
        }
        $system = $item['metadata']['roof_system']['system'] ?? null;
        $text = is_string($system) ? self::searchText($system) : null;
        if ($text === null) {
            return $item;
        }
        $search = (string) ($item['normative_search_text'] ?? '');
        $search = str_starts_with($search, $text) ? $search : $text;
        $item['name'] = $text;
        $item['description'] = $search;
        $item['normative_search_text'] = $search;
        unset($item['work_intent'], $item['specialization_scenario'], $item['specialization_evidence'], $item['normative_rate_code']);
        unset($item['metadata']['specialization_scenario'], $item['metadata']['material_assumption'], $item['metadata']['material_scenario_work_key']);
        $sourceEvidence = $item['metadata']['roof_system']['evidence_ids'] ?? [];
        $refs = array_values(array_filter(is_array($sourceEvidence) ? $sourceEvidence : [],
            static fn ($id): bool => is_string($id) && preg_match('/\Aevidence:[1-9][0-9]*\z/', $id) === 1));
        if ($refs !== []) {
            $item['specialization_evidence'] = [['text' => $text, 'source' => 'user_confirmation', 'evidence_refs' => $refs]];
        }

        return $item;
    }

    public static function compatible(string $system, string $text): bool
    {
        $text = mb_strtolower($text);
        if (preg_match('/кров(?:л|ел)/u', $text) !== 1) {
            return false;
        }

        return match ($system) {
            'concrete_tile' => preg_match('/цементно[\s-]*песчан|бетонн/u', $text) === 1 && str_contains($text, 'черепиц')
                && preg_match('/металлочереп|битум|гибк|фальц/u', $text) !== 1,
            'metal_tile' => str_contains($text, 'металлочерепиц'),
            'flexible_shingle' => str_contains($text, 'черепиц') && preg_match('/гибк|битумн/u', $text) === 1,
            'standing_seam' => str_contains($text, 'фальц') || preg_match('/листов[а-я\s]+стал/u', $text) === 1,
            default => false,
        };
    }
}
