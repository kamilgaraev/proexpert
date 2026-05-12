<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\OneCExchange;

use App\Enums\OneCExchangeScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ManualOneCExchangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scope' => ['required', 'string', Rule::in(array_column(OneCExchangeScope::cases(), 'value'))],
            'items' => ['sometimes', 'array'],
            'filters' => ['sometimes', 'array'],
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }
}
