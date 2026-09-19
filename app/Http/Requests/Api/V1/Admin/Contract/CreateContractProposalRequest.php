<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;

final class CreateContractProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'base_revision' => ['required', 'integer', 'min:1'],
            'draft_version' => ['required', 'integer', 'min:1'],
            'message' => ['nullable', 'string', 'max:2000'],
            'request_key' => ['required', 'string', 'max:191'],
        ];
    }
}
