<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Http\Requests;

use function trans_message;

final class DealConversionConvertRequest extends DealConversionPreviewRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'idempotency_key' => ['required', 'string', 'min:12', 'max:128'],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'idempotency_key.required' => trans_message('crm.conversion.validation.idempotency_key_required'),
            'idempotency_key.min' => trans_message('crm.conversion.validation.idempotency_key_invalid'),
            'idempotency_key.max' => trans_message('crm.conversion.validation.idempotency_key_invalid'),
        ]);
    }
}
