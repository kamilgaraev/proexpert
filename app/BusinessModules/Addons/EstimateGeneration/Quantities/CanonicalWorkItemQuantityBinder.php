<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

final readonly class CanonicalWorkItemQuantityBinder
{
    public function bind(array $workItem, array $quantities, ?WorkItemQuantityResolver $quantityResolver = null, array $generationIdentity = []): array
    {
        if (($workItem['metadata']['generation_source'] ?? null) === 'project_document_normative_reference') {
            if (($workItem['item_type'] ?? null) === 'priced_work') {
                $workItem['item_type'] = 'quantity_review';
                $workItem['pricing_status'] = 'not_applicable';
                $workItem['pricing_blocker'] = 'quantity_review_required';
                $workItem['validation_flags'] = array_values(array_unique([...($workItem['validation_flags'] ?? []), 'quantity_review_required']));
                $workItem['metadata']['display_role'] = 'quantity_review';
                $workItem['metadata']['normative_grounding_policy'] = 'quantity_confirmation_required';
            }
            unset($workItem['quantity_evidence'], $workItem['quantity_evidence_id'], $workItem['quantity_evidence_fingerprint'], $workItem['quantity_evidence_source_version']);

            return $workItem;
        }
        $quantity = ($quantityResolver ?? new WorkItemQuantityResolver)
            ->resolve($workItem, $quantities, $generationIdentity)?->toArray();
        if (! is_array($quantity)) {
            unset($workItem['quantity'], $workItem['quantity_evidence']);
            $workItem['pricing_status'] = 'not_calculated';
            $workItem['pricing_blocker'] = 'quantity_mapping_missing';
            $workItem['validation_flags'] = array_values(array_unique([
                ...(is_array($workItem['validation_flags'] ?? null) ? $workItem['validation_flags'] : []),
                'quantity_mapping_missing',
                'requires_quantity_review',
            ]));

            return $workItem;
        }
        $workItem['quantity'] = $quantity['amount'];
        $workItem['unit'] = $quantity['unit'];
        $workItem['quantity_evidence'] = $quantity;
        $workItem['validation_flags'] = array_values(array_filter(
            is_array($workItem['validation_flags'] ?? null) ? $workItem['validation_flags'] : [],
            static fn (string $flag): bool => ! in_array($flag, [
                'document_takeoff_required',
                'quantity_mapping_missing',
                'requires_quantity_review',
            ], true)
        ));
        if ($quantity['review_blockers'] !== []) {
            $workItem['pricing_status'] = 'not_calculated';
            $workItem['pricing_blocker'] = 'quantity_review_required';
            $workItem['validation_flags'] = array_values(array_unique([
                ...(is_array($workItem['validation_flags'] ?? null) ? $workItem['validation_flags'] : []),
                ...$quantity['review_blockers'],
                'requires_quantity_review',
            ]));
        } elseif (in_array($workItem['pricing_blocker'] ?? null, [
            'quantity_mapping_missing',
            'quantity_review_required',
        ], true)) {
            $workItem['pricing_blocker'] = 'normative_required';
        }

        return $workItem;
    }
}
