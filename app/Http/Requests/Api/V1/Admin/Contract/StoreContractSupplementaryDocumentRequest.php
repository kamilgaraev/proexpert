<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;

final class StoreContractSupplementaryDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'template_id' => ['required', 'uuid'],
            'template_version' => ['required', 'integer', 'min:1'],
            'number' => ['required', 'string', 'max:191'],
            'agreement_date' => ['required', 'date_format:Y-m-d'],
            'request_key' => ['required', 'string', 'max:191'],
        ];
    }
}
