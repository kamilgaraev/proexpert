<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ApproveChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && (int) $this->attributes->get('current_organization_id') > 0;
    }

    public function rules(): array
    {
        return [
            'approved_cost_amount' => ['required', 'string', 'regex:/^-?\d+(?:\.\d{1,2})?$/'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
