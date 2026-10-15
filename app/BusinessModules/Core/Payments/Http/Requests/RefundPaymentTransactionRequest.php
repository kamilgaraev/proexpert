<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Requests;

use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Core\Payments\Services\PaymentTransactionAccessService;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;

class RefundPaymentTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organizationId = (int) $this->attributes->get('current_organization_id');
        $actor = $this->user();
        if (! $actor instanceof User || $organizationId <= 0) {
            return false;
        }
        $id = filter_var($this->route('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw (new ModelNotFoundException)->setModel(PaymentTransaction::class);
        }
        app(PaymentTransactionAccessService::class)->find($organizationId, $actor, $id, 'payments.transaction.refund');

        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:500'],
            'refund_date' => ['nullable', 'date'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:128'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => trim((string) $this->header('Idempotency-Key')),
        ]);
    }
}
