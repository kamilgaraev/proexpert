<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReversePurchaseReceiptLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'reason_code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
        ];
    }
}
