<?php

namespace App\Http\Requests\Api\V1\Admin\Specification;

use Illuminate\Foundation\Http\FormRequest;
use App\DTOs\SpecificationDTO;

class UpdateSpecificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'number' => ['sometimes', 'string', 'max:255', 'unique:specifications,number,'.$this->route('specification')],
            'spec_date' => ['sometimes', 'date_format:Y-m-d'],
            'total_amount' => ['sometimes', 'numeric'],
            'scope_items' => ['sometimes', 'array'],
            'scope_items.*' => ['string'],
            'status' => ['sometimes', 'in:draft,approved,archived'],
        ];
    }

    public function toDto(): SpecificationDTO
    {
        return new SpecificationDTO(
            number: $this->validated('number'),
            spec_date: $this->validated('spec_date'),
            total_amount: $this->validated('total_amount') !== null ? (float) $this->validated('total_amount') : 0,
            scope_items: $this->validated('scope_items') ?? [],
            status: $this->validated('status') ?? 'draft',
        );
    }
} 