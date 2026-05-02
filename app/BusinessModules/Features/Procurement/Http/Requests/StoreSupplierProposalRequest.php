<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Requests;

use App\BusinessModules\Features\Procurement\Enums\SupplierProposalCurrencyEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierProposalIntakeSourceEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierProposalVatModeEnum;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

use function trans_message;

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
            'subtotal_amount' => 'required|numeric|min:0',
            'delivery_amount' => 'required|numeric|min:0',
            'vat_amount' => 'required|numeric|min:0',
            'total_amount' => 'required|numeric|min:0',
            'currency' => ['required', 'string', Rule::enum(SupplierProposalCurrencyEnum::class)],
            'vat_mode' => ['required', 'string', Rule::enum(SupplierProposalVatModeEnum::class)],
            'vat_rate' => 'sometimes|nullable|numeric|min:0|max:100',
            'valid_until' => 'required|date|after:today',
            'delivery_due_date' => 'sometimes|nullable|date',
            'lead_time_days' => 'sometimes|nullable|integer|min:0|max:3650',
            'payment_terms' => 'required|string|max:5000',
            'delivery_terms' => 'required|string|max:5000',
            'warranty_terms' => 'sometimes|nullable|string|max:5000',
            'items' => 'sometimes|array',
            'items.*.supplier_request_line_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('supplier_request_lines', 'id')->where(static function ($query) use ($organizationId) {
                    $query->whereIn('supplier_request_id', static function ($subquery) use ($organizationId) {
                        $subquery->select('id')
                            ->from('supplier_requests')
                            ->where('organization_id', $organizationId)
                            ->whereNull('deleted_at');
                    });
                }),
            ],
            'items.*.name' => 'required_with:items|string|max:1000',
            'items.*.quantity' => 'required_with:items|numeric|min:0',
            'items.*.unit' => 'required_with:items|string|max:50',
            'items.*.unit_price' => 'required_with:items|numeric|min:0',
            'items.*.total_amount' => 'sometimes|numeric|min:0',
            'items.*.comment' => 'sometimes|nullable|string|max:1000',
            'items.*.metadata' => 'sometimes|array',
            'intake' => 'sometimes|array',
            'intake.source' => ['sometimes', 'nullable', 'string', Rule::enum(SupplierProposalIntakeSourceEnum::class)],
            'intake.received_at' => 'sometimes|required_with:intake|date',
            'intake.external_reference' => 'sometimes|nullable|string|max:1000',
            'intake.comment' => 'sometimes|nullable|string|max:5000',
            'intake.attachment_ids' => 'sometimes|array',
            'intake.attachment_ids.*' => 'integer',
            'notes' => 'sometimes|string|max:5000',
            'metadata' => 'sometimes|array',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->filled('delivery_due_date') || $this->filled('lead_time_days')) {
                return;
            }

            $validator->errors()->add(
                'delivery_due_date',
                trans_message('procurement_enterprise.proposals.delivery_schedule_required')
            );
        });
    }
}
