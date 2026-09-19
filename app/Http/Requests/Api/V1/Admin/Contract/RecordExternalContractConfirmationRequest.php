<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RecordExternalContractConfirmationRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return ['content_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'request_key' => ['required', 'string', 'max:191'],
            'side' => ['required', Rule::in(['first', 'second'])], 'basis' => ['required', 'string', 'max:2000'], 'asset_id' => ['required', 'integer', 'min:1']];
    }
}
