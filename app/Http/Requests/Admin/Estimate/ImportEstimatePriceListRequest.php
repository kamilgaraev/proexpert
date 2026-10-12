<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Estimate;

use Illuminate\Foundation\Http\FormRequest;

final class ImportEstimatePriceListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('columns'))) {
            $this->merge(['columns' => json_decode($this->input('columns'), true)]);
        }
    }

    public function rules(): array
    {
        $inspect = $this->routeIs('*.price_lists.inspect');

        return [
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:10240'],
            'name' => [$inspect ? 'sometimes' : 'required', 'string', 'max:150'],
            'currency' => [$inspect ? 'sometimes' : 'required', 'regex:/^[A-Z]{3}$/'],
            'effective_date' => [$inspect ? 'sometimes' : 'required', 'date_format:Y-m-d'],
            'vat_rate' => [$inspect ? 'sometimes' : 'required', 'numeric', 'min:0', 'max:100'],
            'includes_vat' => [$inspect ? 'sometimes' : 'required', 'boolean'],
            'header_row' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'columns' => [$inspect ? 'sometimes' : 'required', 'array:code,name,item_type,unit,unit_price'],
            'columns.code' => [$inspect ? 'sometimes' : 'required', 'integer', 'min:0', 'max:99'],
            'columns.name' => [$inspect ? 'sometimes' : 'required', 'integer', 'min:0', 'max:99'],
            'columns.item_type' => [$inspect ? 'sometimes' : 'required', 'integer', 'min:0', 'max:99'],
            'columns.unit' => [$inspect ? 'sometimes' : 'required', 'integer', 'min:0', 'max:99'],
            'columns.unit_price' => [$inspect ? 'sometimes' : 'required', 'integer', 'min:0', 'max:99'],
        ];
    }
}
