<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Requests;

use App\BusinessModules\Features\Procurement\Contracts\PurchaseReceiptReturnAuthorizer;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class ReturnPurchaseReceiptLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->user();
        $organizationId = (int) $this->attributes->get('current_organization_id');
        $purchaseOrderId = (int) $this->route('id');
        $receiptLineId = (int) $this->route('line');

        return $user !== null
            && $organizationId > 0
            && app(PurchaseReceiptReturnAuthorizer::class)->canReturn(
                $user,
                $organizationId,
                $purchaseOrderId,
                $receiptLineId,
            );
    }

    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason_code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ];
    }
}
