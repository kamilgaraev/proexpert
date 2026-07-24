<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Estimates\Constructor;

use Illuminate\Foundation\Http\FormRequest;

class AddItemsFromCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.catalog_item_id' => ['required', 'integer', 'exists:estimate_position_catalog,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            'items.*.section_id' => ['nullable', 'integer', 'exists:estimate_sections,id'],
        ];
    }
}
