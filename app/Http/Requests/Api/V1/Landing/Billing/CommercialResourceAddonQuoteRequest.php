<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Landing\Billing;

use Illuminate\Foundation\Http\FormRequest;

class CommercialResourceAddonQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resources' => ['required', 'array', 'min:1', 'max:20'],
            'resources.*.slug' => ['required', 'string', 'max:100'],
            'resources.*.quantity' => ['required', 'numeric', 'min:0'],
        ];
    }
}
