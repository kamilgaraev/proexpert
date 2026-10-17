<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveSessionPricingSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['request_id' => ['required', 'uuid'], 'state_version' => ['required', 'integer', 'min:0'],
            'estimate_regional_price_version_id' => ['required', 'integer', 'min:1'],
            'conditions' => ['sometimes', 'array:as_of_date,vat_mode,vat_rate,delivery,basis'],
            'conditions.as_of_date' => ['required_with:conditions', 'date_format:Y-m-d'],
            'conditions.vat_mode' => ['required_with:conditions', 'in:excluded,not_applicable'],
            'conditions.vat_rate' => ['required_with:conditions', 'string', 'max:12'],
            'conditions.delivery' => ['required_with:conditions', 'in:included,outside_scope'],
            'conditions.basis' => ['required_with:conditions', 'string', 'min:10', 'max:1000']];
    }
}
