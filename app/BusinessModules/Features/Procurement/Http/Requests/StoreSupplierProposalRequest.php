<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Requests;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->user();
        $organizationId = (int) $this->attributes->get('current_organization_id');

        if (!$user || $organizationId <= 0) {
            return false;
        }

        return app(AuthorizationService::class)->can($user, 'procurement.supplier_proposals.create', [
            'organization_id' => $organizationId,
        ]);
    }

    public function rules(): array
    {
        $organizationId = (int) $this->attributes->get('current_organization_id');

        return [
            'supplier_request_id' => [
                'required',
                'integer',
                Rule::exists('supplier_requests', 'id')->where(static function ($query) use ($organizationId) {
                    $query->where('organization_id', $organizationId)
                        ->whereNull('deleted_at');
                }),
            ],
            'proposal_date' => 'sometimes|date',
            'subtotal_amount' => 'sometimes|numeric|min:0',
            'delivery_amount' => 'sometimes|numeric|min:0',
            'vat_amount' => 'sometimes|numeric|min:0',
            'total_amount' => 'required|numeric|min:0',
            'currency' => 'sometimes|string|size:3',
            'valid_until' => 'sometimes|date|after:today',
            'payment_terms' => 'sometimes|nullable|string|max:5000',
            'delivery_terms' => 'sometimes|nullable|string|max:5000',
            'items' => 'sometimes|array',
            'items.*.supplier_request_line_id' => 'sometimes|nullable|integer|exists:supplier_request_lines,id',
            'items.*.name' => 'required_with:items|string|max:1000',
            'items.*.quantity' => 'required_with:items|numeric|min:0',
            'items.*.unit' => 'required_with:items|string|max:50',
            'items.*.unit_price' => 'required_with:items|numeric|min:0',
            'items.*.total_amount' => 'sometimes|numeric|min:0',
            'items.*.comment' => 'sometimes|nullable|string|max:1000',
            'items.*.metadata' => 'sometimes|array',
            'notes' => 'sometimes|string|max:5000',
            'metadata' => 'sometimes|array',
        ];
    }
}

