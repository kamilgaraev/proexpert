<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MobileBudgetingExecutionCardsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && (int) $this->attributes->get('current_organization_id', 0) > 0;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
