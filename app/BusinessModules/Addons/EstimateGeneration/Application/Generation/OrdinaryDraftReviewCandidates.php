<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Generation;

use Illuminate\Validation\ValidationException;

final class OrdinaryDraftReviewCandidates
{
    public function restore(array $draft, ?string $workItemKey = null): array
    {
        if (($draft['generation_contract'] ?? null) !== BuildMostEstimateDraft::CONTRACT) {
            return $draft;
        }
        $remaining = [];
        $quantityReviews = [];
        foreach ($draft['stage6_review_items'] ?? [] as $review) {
            if (($review['type'] ?? null) === 'quantity_blocking' && is_string($review['work_item_key'] ?? null)) {
                $quantityReviews[$review['work_item_key']] = true;
            }
        }
        foreach ($draft['stage6_candidate_rows'] ?? [] as $candidate) {
            if (! is_array($candidate) || ! is_string($candidate['key'] ?? null) || $candidate['key'] === '') {
                $this->reject();
            }
            if ($workItemKey !== null && $candidate['key'] !== $workItemKey) {
                $remaining[] = $candidate;

                continue;
            }
            foreach ($draft['local_estimates'] ?? [] as $estimate) {
                foreach ($estimate['sections'] ?? [] as $section) {
                    foreach ($section['work_items'] ?? [] as $item) {
                        if (($item['key'] ?? null) === $candidate['key']) {
                            $this->reject();
                        }
                    }
                }
            }
            [$localIndex, $sectionIndex] = $this->location($draft, $candidate);
            $candidate['metadata']['stage6_review_pending'] = true;
            if (isset($quantityReviews[$candidate['key']]) && ($candidate['metadata']['quantity_feedback']['status'] ?? null) !== 'confirmed_by_user') {
                $candidate['item_type'] = 'quantity_review';
            }
            $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][] = $candidate;
        }
        $draft['stage6_candidate_rows'] = $remaining;

        return $draft;
    }

    public function stash(array $draft): array
    {
        foreach ($draft['local_estimates'] ?? [] as $localIndex => $estimate) {
            foreach ($estimate['sections'] ?? [] as $sectionIndex => $section) {
                $kept = [];
                foreach ($section['work_items'] ?? [] as $item) {
                    if (($item['metadata']['stage6_review_pending'] ?? false) === true) {
                        $draft['stage6_candidate_rows'][] = $item;
                    } else {
                        $kept[] = $item;
                    }
                }
                $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'] = $kept;
            }
        }

        return $draft;
    }

    public function pending(array $draft): bool
    {
        if (($draft['generation_contract'] ?? null) !== BuildMostEstimateDraft::CONTRACT) {
            return false;
        }
        if (($draft['stage6_candidate_rows'] ?? []) !== []) {
            return true;
        }
        foreach ($draft['local_estimates'] ?? [] as $estimate) {
            foreach ($estimate['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $item) {
                    if (($item['metadata']['stage6_review_pending'] ?? false) === true) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function prepareRebuild(array $draft): array
    {
        $draft['stage6_review_items'] = array_values(array_filter($draft['stage6_review_items'] ?? [],
            static fn (mixed $item): bool => ! is_array($item) || ($item['work_item_key'] ?? null) === null));
        unset($draft['independent_audit']);

        return $draft;
    }

    public function pruneRemovedReviews(array $draft): array
    {
        $keys = array_fill_keys(array_column($draft['stage6_candidate_rows'] ?? [], 'key'), true);
        foreach ($draft['local_estimates'] ?? [] as $estimate) {
            foreach ($estimate['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $item) {
                    if (is_string($item['key'] ?? null)) {
                        $keys[$item['key']] = true;
                    }
                }
            }
        }
        $draft['stage6_review_items'] = array_values(array_filter($draft['stage6_review_items'] ?? [],
            static fn (mixed $item): bool => ! is_array($item) || ! is_string($item['work_item_key'] ?? null) || isset($keys[$item['work_item_key']])));

        return $draft;
    }

    public function evaluationView(array $draft): array
    {
        $quantityErrors = [];
        foreach ($draft['stage6_review_items'] ?? [] as $review) {
            if (($review['type'] ?? null) === 'quantity_blocking' && is_string($review['work_item_key'] ?? null)) {
                $quantityErrors[$review['work_item_key']] = true;
            }
        }
        $draft = $this->restore($draft);
        foreach ($draft['local_estimates'] ?? [] as $localIndex => $estimate) {
            foreach ($estimate['sections'] ?? [] as $sectionIndex => $section) {
                foreach ($section['work_items'] ?? [] as $itemIndex => $item) {
                    if (($item['metadata']['stage6_review_pending'] ?? false) !== true) {
                        continue;
                    }
                    $item['item_type'] = 'priced_work';
                    if (isset($quantityErrors[$item['key']])) {
                        $item['quantity'] = null;
                        $item['quantity_status'] = 'unknown';
                    }
                    unset($item['price_snapshot'], $item['commercial_price_snapshot'], $item['evaluation_price_basis_quantity']);
                    $item['pricing_status'] = 'not_calculated';
                    $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$itemIndex] = $item;
                }
            }
        }

        return $draft;
    }

    private function location(array &$draft, array $candidate): array
    {
        $origin = $candidate['metadata']['stage6_review_origin'] ?? [];
        $matches = [];
        foreach ($draft['local_estimates'] ?? [] as $localIndex => $estimate) {
            foreach ($estimate['sections'] ?? [] as $sectionIndex => $section) {
                if (is_string($origin['local_estimate_key'] ?? null) && is_string($origin['section_key'] ?? null)
                    && ($estimate['key'] ?? null) === $origin['local_estimate_key'] && ($section['key'] ?? null) === $origin['section_key']) {
                    $matches[] = [$localIndex, $sectionIndex];
                }
            }
        }
        if (count($matches) === 1) {
            return $matches[0];
        }
        if (count($matches) > 1) {
            $this->reject();
        }
        foreach ($draft['local_estimates'] ?? [] as $localIndex => $estimate) {
            if (($estimate['key'] ?? null) === 'stage6-review') {
                return [$localIndex, 0];
            }
        }
        $draft['local_estimates'][] = ['key' => 'stage6-review', 'title' => trans_message('estimate_generation.candidate_review_title'),
            'scope_type' => 'review', 'source_refs' => [], 'sections' => [['key' => 'stage6-review',
                'title' => trans_message('estimate_generation.candidate_review_title'), 'source_refs' => [], 'work_items' => []]]];

        return [array_key_last($draft['local_estimates']), 0];
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['draft' => trans_message('estimate_generation.stage6_snapshot_stale')]);
    }
}
