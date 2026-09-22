<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;

final class ActivateContractSupplementaryDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'content_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'previous_document_id' => ['present', 'nullable', 'integer', 'min:1'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'basis' => ['required', 'string', 'max:2000'],
            'request_key' => ['required', 'string', 'max:191'],
        ];
    }
}
