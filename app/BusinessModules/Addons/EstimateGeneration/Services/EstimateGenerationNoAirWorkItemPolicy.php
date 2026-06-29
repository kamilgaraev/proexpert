<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;

final class EstimateGenerationNoAirWorkItemPolicy
{
    public const BLOCKER = 'generic_work_item_requires_review';
    public const FLAG = 'generic_work_item_requires_review';
    public const NO_AIR_FLAG = 'no_air_generic_work_item';

    private const GENERIC_TITLES = [
        'благоустройство территории',
        'дороги и площадки',
        'инженерные системы',
        'комплекс работ',
        'комплекс строительных работ',
        'контроль качества',
        'крепление',
        'наружные сети',
        'общестроительные работы',
        'основной монтаж',
        'основные строительные работы',
        'подготовительные работы',
        'подготовка фронта работ',
        'поставка материалов',
        'разметка трасс',
        'строительные работы',
    ];

    /**
     * @param array<string, mixed> $workItem
     */
    public function requiresReview(array $workItem): bool
    {
        if (!$this->isPricedWorkItem($workItem)) {
            return false;
        }

        $flags = $this->flags($workItem);

        if (
            (string) ($workItem['pricing_blocker'] ?? '') === self::BLOCKER
            || in_array(self::FLAG, $flags, true)
            || in_array(self::NO_AIR_FLAG, $flags, true)
        ) {
            return true;
        }

        return $this->isGenericTitle((string) ($workItem['name'] ?? ''))
            || $this->isGenericTitle((string) ($workItem['normative_search_text'] ?? ''));
    }

    /**
     * @param array<string, mixed> $workItem
     * @return array<string, mixed>
     */
    public function markRequiresReview(array $workItem, ?string $message = null): array
    {
        $flags = array_values(array_unique([
            ...$this->flags($workItem),
            self::NO_AIR_FLAG,
            self::FLAG,
            'safe_norm_required',
            'requires_normative_review',
            'pricing_not_calculated',
        ]));

        $workItem['materials'] = [];
        $workItem['labor'] = [];
        $workItem['machinery'] = [];
        $workItem['other_resources'] = [];
        $workItem['work_cost'] = 0;
        $workItem['materials_cost'] = 0;
        $workItem['machinery_cost'] = 0;
        $workItem['labor_cost'] = 0;
        $workItem['total_cost'] = 0;
        $workItem['price_source'] = null;
        $workItem['pricing_status'] = 'not_calculated';
        $workItem['pricing_blocker'] = self::BLOCKER;
        $workItem['pricing_blocker_message'] = $message ?? ($workItem['pricing_blocker_message'] ?? null);
        $workItem['validation_flags'] = $flags;
        $workItem['metadata'] = [
            ...(is_array($workItem['metadata'] ?? null) ? $workItem['metadata'] : []),
            'no_air_policy' => 'generic_priced_work_item',
        ];

        return $workItem;
    }

    /**
     * @param array<string, mixed> $workItem
     */
    private function isPricedWorkItem(array $workItem): bool
    {
        $type = (string) ($workItem['item_type'] ?? 'priced_work');

        return $type !== EstimateGenerationPackageItem::QUANTITY_REVIEW_ITEM_TYPE
            && !in_array($type, EstimateGenerationPackageItem::SERVICE_ITEM_TYPES, true);
    }

    private function isGenericTitle(string $title): bool
    {
        $title = $this->normalize($title);

        if ($title === '') {
            return false;
        }

        if (in_array($title, self::GENERIC_TITLES, true)) {
            return true;
        }

        return preg_match('/^комплекс\s+.+\s+работ$/u', $title) === 1;
    }

    /**
     * @param array<string, mixed> $workItem
     * @return array<int, string>
     */
    private function flags(array $workItem): array
    {
        return array_values(array_unique(array_filter(array_map('strval', [
            ...(is_array($workItem['validation_flags'] ?? null) ? $workItem['validation_flags'] : []),
            ...(is_array($workItem['flags'] ?? null) ? $workItem['flags'] : []),
        ]))));
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace('ё', 'е', $value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;

        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }
}
