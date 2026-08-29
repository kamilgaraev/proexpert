<?php

namespace App\Http\Requests\Api\V1\Admin\Specification;

use Illuminate\Foundation\Http\FormRequest;

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

    public function toPayload(): array
    {
        $payload = $this->validated();
        if (array_key_exists('total_amount', $payload)) {
            $payload['total_amount'] = (float) $payload['total_amount'];
        }

        return $payload;
    }
}
