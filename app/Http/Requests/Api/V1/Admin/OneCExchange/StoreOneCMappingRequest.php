<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\OneCExchange;

use App\Enums\OneCExchangeScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreOneCMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scope' => ['required', 'string', Rule::in(array_column(OneCExchangeScope::cases(), 'value'))],
            'external_type' => ['nullable', 'string', 'max:80'],
            'external_id' => ['required', 'string', 'max:191'],
            'external_name' => ['nullable', 'string', 'max:255'],
            'local_type' => ['required', 'string', Rule::in([
                'contractors',
                'contracts',
                'users',
                'employees',
                'projects',
                'organizations',
                'materials',
                'cost_categories',
                'cost_centers',
                'warehouses',
                'payment_documents',
                'procurement_documents',
            ])],
            'local_id' => ['required', 'integer', 'min:1'],
            'local_display_name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive', 'needs_review', 'conflict', 'superseded', 'archived'])],
            'confidence_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'source' => ['nullable', 'string', Rule::in(['manual', 'automatic', 'imported', 'suggested'])],
            'duplicate_warning' => ['nullable', 'boolean'],
            'safe_payload_preview' => ['nullable', 'array'],
            'payload' => ['nullable', 'array'],
        ];
    }
}
