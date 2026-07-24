<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests\EstimateItems;

use App\Enums\EstimatePositionItemType;
use App\Models\EstimateItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class EstimateItemRequest extends FormRequest
{
    protected const MAX_BULK_ITEMS = 200;

    public function authorize(): bool
    {
        return true;
    }

    protected function estimateId(): int
    {
        $estimateId = $this->route('estimate');

        if ($estimateId !== null) {
            return (int) $estimateId;
        }

        $item = $this->route('item');

        if ($item instanceof EstimateItem) {
            return (int) $item->estimate_id;
        }

        return 0;
    }

    protected function organizationId(): int
    {
        return (int) $this->attributes->get('current_organization_id');
    }

    protected function nullableSectionRule(): array
    {
        return [
            'nullable',
            'integer',
            Rule::exists('estimate_sections', 'id')->where('estimate_id', $this->estimateId()),
        ];
    }

    protected function requiredSectionRule(): array
    {
        return [
            'required',
            'integer',
            Rule::exists('estimate_sections', 'id')->where('estimate_id', $this->estimateId()),
        ];
    }

    protected function scopedWorkTypeRule(string $presence = 'nullable'): array
    {
        return [
            $presence,
            'nullable',
            'integer',
            Rule::exists('work_types', 'id')->where('organization_id', $this->organizationId()),
        ];
    }

    protected function scopedMeasurementUnitRule(string $presence = 'nullable'): array
    {
        return [
            $presence,
            'nullable',
            'integer',
            Rule::exists('measurement_units', 'id')->where('organization_id', $this->organizationId()),
        ];
    }

    protected function itemTypeRule(string $presence = 'required'): array
    {
        return [$presence, Rule::in(EstimatePositionItemType::values())];
    }
}
