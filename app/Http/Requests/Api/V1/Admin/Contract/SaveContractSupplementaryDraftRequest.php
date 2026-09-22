<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;

final class SaveContractSupplementaryDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'values' => ['required', 'array'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'request_key' => ['required', 'string', 'max:191'],
        ];
    }
}
