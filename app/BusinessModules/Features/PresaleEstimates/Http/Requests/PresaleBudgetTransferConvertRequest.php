<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\PresaleEstimates\Http\Requests;

use function trans_message;

final class PresaleBudgetTransferConvertRequest extends PresaleBudgetTransferRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'idempotency_key' => ['required', 'string', 'min:12', 'max:128'],
            'confirmed' => ['accepted'],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'idempotency_key.required' => trans_message('presale_estimates.budget_transfer.validation.idempotency_key_required'),
            'idempotency_key.min' => trans_message('presale_estimates.budget_transfer.validation.idempotency_key_invalid'),
            'idempotency_key.max' => trans_message('presale_estimates.budget_transfer.validation.idempotency_key_invalid'),
            'confirmed.accepted' => trans_message('presale_estimates.budget_transfer.validation.confirmed_required'),
        ]);
    }
}
