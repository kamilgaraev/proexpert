<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Planning\EvaluationSectionMap;
use Illuminate\Validation\ValidationException;

final class EvaluationWorkItemLocator
{
    public function replace(array $draft, array $input, string $workKey, array $replacement): array
    {
        $this->find($draft, $input, $workKey);
        if (($replacement['key'] ?? null) !== $workKey) {
            throw ValidationException::withMessages(['work_item_key' => trans_message('estimate_evaluation.work_missing_or_ambiguous')]);
        }
        $count = 0;
        foreach ($draft['local_estimates'] ?? [] as $estimateIndex => $estimate) {
            foreach ($estimate['sections'] ?? [] as $sectionIndex => $section) {
                foreach ($section['work_items'] ?? [] as $itemIndex => $item) {
                    if (($item['key'] ?? null) === $workKey) {
                        $draft['local_estimates'][$estimateIndex]['sections'][$sectionIndex]['work_items'][$itemIndex] = $replacement;
                        $count++;
                    }
                }
            }
        }
        if ($count !== 1) {
            throw ValidationException::withMessages(['work_item_key' => trans_message('estimate_evaluation.work_missing_or_ambiguous')]);
        }

        return $draft;
    }

    public function find(array $draft, array $input, string $workKey): array
    {
        $matches = [];
        $selected = $input['selected_sections'] ?? [];
        foreach ($draft['local_estimates'] ?? [] as $estimate) {
            $package = (string) ($estimate['key'] ?? '');
            $sectionKey = EvaluationSectionMap::forPackage($package);
            if ($selected !== [] && ! in_array($package, $selected, true) && ! in_array($sectionKey, $selected, true)) {
                continue;
            }
            foreach ($estimate['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $candidate) {
                    if (($candidate['key'] ?? null) === $workKey
                        && ! in_array($candidate['item_type'] ?? 'priced_work', ['operation', 'review_note', 'resource_note', 'quantity_review'], true)) {
                        $matches[] = $candidate;
                    }
                }
            }
        }
        if (count($matches) !== 1) {
            throw ValidationException::withMessages(['work_item_key' => trans_message('estimate_evaluation.work_missing_or_ambiguous')]);
        }

        return $matches[0];
    }
}
