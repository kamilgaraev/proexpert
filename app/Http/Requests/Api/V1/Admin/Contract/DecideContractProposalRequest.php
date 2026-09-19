<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DecideContractProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:1'],
            'decision' => ['required', Rule::in(['accepted', 'rejected'])],
            'reason' => ['nullable', 'string', 'max:2000'],
            'request_key' => ['required', 'string', 'max:191'],
        ];
    }
}
