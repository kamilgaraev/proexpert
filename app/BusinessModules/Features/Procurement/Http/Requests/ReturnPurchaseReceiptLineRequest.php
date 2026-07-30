<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Requests;

use App\Domain\Authorization\Services\AuthorizationService;
use Illuminate\Foundation\Http\FormRequest;

final class ReturnPurchaseReceiptLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $organizationId = (int) $this->attributes->get('current_organization_id');

        return $user !== null
            && $organizationId > 0
            && app(AuthorizationService::class)->can(
                $user,
                'procurement.purchase_orders.receive',
                ['organization_id' => $organizationId],
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
