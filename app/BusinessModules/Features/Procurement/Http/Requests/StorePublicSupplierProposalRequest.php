<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Requests;

use App\BusinessModules\Features\Procurement\Enums\SupplierProposalVatModeEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Http\Responses\PublicSupplierRequestAccessResponse;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Services\ProcurementLifecycleService;
use App\BusinessModules\Features\Procurement\Support\SupplierPaymentSchedule;
use App\Enums\CurrencyCode;
use App\Http\Responses\LandingResponse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

use function trans_message;

class StorePublicSupplierProposalRequest extends FormRequest
{
    private ?SupplierRequest $resolvedSupplierRequest = null;

    public function authorize(ProcurementLifecycleService $lifecycleService): bool
    {
        $supplierRequest = $this->supplierRequest();

        if (! $supplierRequest instanceof SupplierRequest) {
            throw new HttpResponseException(LandingResponse::error(trans_message('procurement.public_supplier_requests.not_found'), 404));
        }

        if ($supplierRequest->status === SupplierRequestStatusEnum::SENT && $supplierRequest->hasExpiredPublicToken()) {
            $supplierRequest = $lifecycleService->syncSupplierRequestExpiry($supplierRequest);
            $this->resolvedSupplierRequest = $supplierRequest;
        }

        $blockedResponse = PublicSupplierRequestAccessResponse::blocked($supplierRequest, true);

        if ($blockedResponse !== null) {
            throw new HttpResponseException($blockedResponse);
        }

        return true;
    }

    public function rules(): array
    {
        $supplierRequestId = $this->supplierRequest()?->id ?? 0;

        return [
            'proposal_date' => 'sometimes|date',
            'subtotal_amount' => 'required|numeric|min:0',
            'delivery_amount' => 'required|numeric|min:0',
            'vat_amount' => 'sometimes|nullable|numeric|min:0',
            'total_amount' => 'required|numeric|min:0',
            'currency' => ['required', 'string', Rule::enum(CurrencyCode::class)],
            'vat_mode' => ['required', 'string', Rule::enum(SupplierProposalVatModeEnum::class)],
            'vat_rate' => 'required|numeric|min:0|max:100',
            'valid_until' => 'required|date|after:today',
            'delivery_due_date' => 'sometimes|nullable|date',
            'lead_time_days' => 'sometimes|nullable|integer|min:0|max:3650',
            'payment_terms' => 'required|string|max:5000',
            'payment_schedule' => ['sometimes', 'nullable', 'array', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (SupplierPaymentSchedule::normalize($value) === null) {
                    $fail(trans_message('procurement.payment_schedule_invalid'));
                }
            }],
            'delivery_terms' => 'required|string|max:5000',
            'warranty_terms' => 'sometimes|nullable|string|max:5000',
            'items' => 'required|array|min:1',
            'items.*.supplier_request_line_id' => [
                'required',
                'integer',
                Rule::exists('supplier_request_lines', 'id')
                    ->where(static fn ($query) => $query->where('supplier_request_id', $supplierRequestId)),
            ],
            'items.*.name' => 'required|string|max:1000',
            'items.*.quantity' => 'required|numeric|min:0',
            'items.*.unit' => 'required|string|max:50',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.total_amount' => 'sometimes|numeric|min:0',
            'items.*.comment' => 'sometimes|nullable|string|max:1000',
            'notes' => 'sometimes|nullable|string|max:5000',
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

    private function supplierRequest(): ?SupplierRequest
    {
        if ($this->resolvedSupplierRequest !== null) {
            return $this->resolvedSupplierRequest;
        }

        $token = (string) $this->route('token');

        if (strlen($token) < 32) {
            return null;
        }

        return $this->resolvedSupplierRequest = SupplierRequest::query()
            ->where('public_token', $token)
            ->first();
    }
}
