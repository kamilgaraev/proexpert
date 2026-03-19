<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Requests;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->user();
        $organizationId = (int) $this->attributes->get('current_organization_id');

        if (!$user || $organizationId <= 0) {
            return false;
        }

        return app(AuthorizationService::class)->can($user, 'procurement.purchase_orders.create', [
            'organization_id' => $organizationId,
        ]);
    }

    public function rules(): array
    {
        $organizationId = (int) $this->attributes->get('current_organization_id');

        return [
            'purchase_request_id' => [
                'required',
                'integer',
                Rule::exists('purchase_requests', 'id')->where(static function ($query) use ($organizationId) {
                    $query->where('organization_id', $organizationId)
                        ->whereNull('deleted_at');
                }),
            ],
            'supplier_id' => [
                'required',
                'integer',
                Rule::exists('suppliers', 'id')->where(static function ($query) use ($organizationId) {
                    $query->where('organization_id', $organizationId)
                        ->whereNull('deleted_at');
                }),
            ],
            'order_date' => ['sometimes', 'date'],
            'total_amount' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'delivery_date' => ['sometimes', 'date', 'after_or_equal:today'],
            'notes' => ['sometimes', 'string', 'max:5000'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
