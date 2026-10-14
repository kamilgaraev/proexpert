<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Http;

use Illuminate\Foundation\Http\FormRequest;

final class RequestListForm extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'in:all,pending,submitted,awaiting_hr,fulfilled,rejected']];
    }
}
