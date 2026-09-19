<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;

final class ListContractOrganizationHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:50']];
    }
}
