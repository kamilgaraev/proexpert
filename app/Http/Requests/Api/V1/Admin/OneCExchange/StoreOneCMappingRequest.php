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
            'external_id' => ['required', 'string', 'max:191'],
            'external_name' => ['nullable', 'string', 'max:255'],
            'local_type' => ['required', 'string', Rule::in(['contractors', 'users', 'projects', 'materials', 'cost_categories'])],
            'local_id' => ['required', 'integer', 'min:1'],
            'payload' => ['nullable', 'array'],
        ];
    }
}
