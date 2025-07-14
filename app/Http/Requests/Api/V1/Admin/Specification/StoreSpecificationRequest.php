<?php

namespace App\Http\Requests\Api\V1\Admin\Specification;

use Illuminate\Foundation\Http\FormRequest;
use App\DTOs\SpecificationDTO;

class StoreSpecificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'number' => ['required', 'string', 'max:255', 'unique:specifications,number'],
            'spec_date' => ['required', 'date_format:Y-m-d'],
            'total_amount' => ['required', 'numeric'],
            'scope_items' => ['required', 'array'],
            'scope_items.*' => ['string'],
            'status' => ['sometimes', 'in:draft,approved,archived'],
        ];
    }

    public function toDto(): SpecificationDTO
    {
        return new SpecificationDTO(
            number: $this->validated('number'),
            spec_date: $this->validated('spec_date'),
            total_amount: (float) $this->validated('total_amount'),
            scope_items: $this->validated('scope_items'),
            status: $this->validated('status') ?? 'draft',
        );
    }
} 