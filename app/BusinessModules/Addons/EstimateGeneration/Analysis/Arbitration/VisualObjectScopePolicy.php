<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

final readonly class VisualObjectScopePolicy
{
    public function scope(string $category, string $value, bool $conditional): string
    {
        if (in_array($category, ['furniture', 'equipment'], true)) {
            return $conditional ? 'excluded_by_document_note' : 'contextual_only';
        }
        if ($category === 'kitchen_fixture'
            && preg_match('/шкаф|мебел|холодиль|посудомо|духов|cabin|fridge/iu', $value) === 1) {
            return $conditional ? 'excluded_by_document_note' : 'contextual_only';
        }

        return 'requires_confirmation';
    }

    public function isConditionalNote(string $factType, mixed $value): bool
    {
        return $factType === 'note'
            && is_string($value)
            && preg_match('/условн|for reference|indicative/iu', $value) === 1;
    }
}
