<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationWorkItemLocator;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Planning\EvaluationSectionMap;
use Illuminate\Validation\ValidationException;

/** User confirmation supplements source provenance; it never changes the source's amounts or applicability. */
final class ConfirmedCommercialPriceConditions
{
    public const DELIVERY_CONDITIONS = ['delivery_included', 'delivery_cost', 'delivery_allocation'];

    public function required(array $price, ?string $targetRegion): array
    {
        $required = [];
        $regions = new CommercialPriceTargetRegion;
        $sourceRegion = $regions->normalize($price['region'] ?? null);
        if ($sourceRegion === null || $targetRegion === null || mb_strtolower($sourceRegion) !== mb_strtolower($targetRegion)) {
            $required[] = 'region';
        }
        if (array_intersect(self::DELIVERY_CONDITIONS, $price['conditions_missing'] ?? []) !== []) {
            $required[] = 'delivery';
        }

        return $required;
    }

    public function validate(array $price, EstimateGenerationSession $session, array $item, mixed $input): array
    {
        if ($input === null) {
            $input = [];
        }
        if (! is_array($input) || array_diff(array_keys($input), ['region', 'delivery']) !== []) {
            $this->invalid();
        }
        $targetRegion = (new CommercialPriceTargetRegion)->fromSession($session);
        foreach ($this->required($price, $targetRegion) as $key) {
            if (! isset($input[$key])) {
                throw ValidationException::withMessages(['conditions_confirmation.'.$key => trans_message('estimate_evaluation.price_condition_confirmation_required')]);
            }
        }
        if (isset($input['region'])) {
            $region = $input['region'];
            if (! is_array($region) || array_diff(array_keys($region), ['value', 'basis']) !== []) {
                $this->invalid();
            }
            $value = (new CommercialPriceTargetRegion)->normalize($region['value'] ?? null);
            if ($value === null || ! $this->basis($region['basis'] ?? null)
                || ($targetRegion !== null && mb_strtolower($targetRegion) !== mb_strtolower($value))) {
                $this->invalid();
            }
            $input['region'] = ['value' => $value, 'basis' => trim($region['basis'])];
        }
        if (isset($input['delivery'])) {
            $delivery = $input['delivery'];
            if (! is_array($delivery) || array_diff(array_keys($delivery), ['work_item_key', 'basis']) !== []
                || ! is_string($delivery['work_item_key'] ?? null) || strlen($delivery['work_item_key']) < 1 || strlen($delivery['work_item_key']) > 160
                || ! $this->basis($delivery['basis'] ?? null)
                || $delivery['work_item_key'] === ($item['key'] ?? null)) {
                $this->invalid();
            }
            // A separate, explicitly linked work must exist in this session. Its money is verified when a scenario is calculated.
            $linked = (new EvaluationWorkItemLocator)->find($session->draft_payload ?? [], $session->input_payload ?? [], $delivery['work_item_key']);
            $baseScope = $this->scope($session->draft_payload ?? [], $item['key']);
            $deliveryScope = $this->scope($session->draft_payload ?? [], $delivery['work_item_key']);
            if (($linked['metadata']['requirement_key'] ?? $linked['work_key'] ?? null) !== 'delivery'
                || $baseScope === null || $baseScope !== $deliveryScope) {
                $this->invalid();
            }
            $input['delivery'] = ['work_item_key' => $delivery['work_item_key'], 'basis' => trim($delivery['basis'])];
        }

        return $input;
    }

    public function apply(array $price, array $confirmation, ?string $targetRegion): ?array
    {
        $price['source_conditions_missing'] = $price['conditions_missing'] ?? [];
        foreach ($this->required($price, $targetRegion) as $key) {
            if (! isset($confirmation[$key])) {
                return null;
            }
        }
        if (isset($confirmation['region'])) {
            $region = $confirmation['region'];
            $value = is_array($region) ? (new CommercialPriceTargetRegion)->normalize($region['value'] ?? null) : null;
            if ($value === null || ! $this->basis($region['basis'] ?? null)
                || ($targetRegion !== null && mb_strtolower($targetRegion) !== mb_strtolower($value))) {
                return null;
            }
            $price['effective_region'] = $value;
            $price['conditions_missing'] = array_values(array_diff($price['conditions_missing'] ?? [], ['region']));
        }
        if (isset($confirmation['delivery'])) {
            $delivery = $confirmation['delivery'];
            if (! is_array($delivery) || ! is_string($delivery['work_item_key'] ?? null) || ! $this->basis($delivery['basis'] ?? null)) {
                return null;
            }
            $price['delivery_work_item_key'] = $delivery['work_item_key'];
            // Keep delivery unresolved until the linked position is actually priced in the same scenario.
        }

        return [...$price, 'conditions_confirmation' => $confirmation];
    }

    private function basis(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && mb_strlen($value) <= 1000 && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    private function scope(array $draft, string $key): ?array
    {
        foreach ($draft['local_estimates'] ?? [] as $estimate) {
            foreach ($estimate['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $item) {
                    if (($item['key'] ?? null) !== $key) {
                        continue;
                    }
                    $entity = $item['metadata']['scope_entity_id'] ?? $item['quantity_basis_details']['formula_inputs']['entity_id'] ?? $item['metadata']['entity_id'] ?? null;

                    return is_string($entity) && $entity !== '' ? [EvaluationSectionMap::forPackage((string) $estimate['key']), $entity] : null;
                }
            }
        }

        return null;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['conditions_confirmation' => trans_message('estimate_evaluation.price_condition_confirmation_invalid')]);
    }
}
