<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmContractRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['content_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'request_key' => ['required', 'string', 'max:191']];
    }
}
