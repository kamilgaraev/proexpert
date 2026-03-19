<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\OffsetService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

use function trans_message;

class OffsetController extends Controller
{
    public function __construct(
        private readonly OffsetService $offsetService
    ) {}

    public function opportunities(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validate([
                'contractor_id' => [
                    'required',
                    'integer',
                    Rule::exists('contractors', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
                ],
            ]);

            $opportunities = $this->offsetService->getOffsetOpportunities(
                $organizationId,
                (int) $validated['contractor_id']
            );

            return AdminResponse::success($opportunities, trans_message('payments.offsets.opportunities_loaded'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('offset.opportunities.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.offsets.opportunities_error'), 500);
        }
    }

    public function perform(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validate([
                'receivable_id' => [
                    'required',
                    'integer',
                    Rule::exists('payment_documents', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
                ],
                'payable_id' => [
                    'required',
                    'integer',
                    Rule::exists('payment_documents', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
                ],
                'amount' => ['required', 'numeric', 'min:0.01'],
                'notes' => ['nullable', 'string', 'max:500'],
            ]);

            $receivable = PaymentDocument::query()->forOrganization($organizationId)->findOrFail((int) $validated['receivable_id']);
            $payable = PaymentDocument::query()->forOrganization($organizationId)->findOrFail((int) $validated['payable_id']);
            $result = $this->offsetService->performOffset(
                $receivable,
                $payable,
                (float) $validated['amount'],
                (string) ($validated['notes'] ?? '')
            );

            return AdminResponse::success($result, trans_message('payments.offsets.performed'));
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (\Exception $e) {
            Log::error('offset.perform.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.offsets.perform_error'), 500);
        }
    }

    public function auto(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validate([
                'contractor_id' => [
                    'required',
                    'integer',
                    Rule::exists('contractors', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
                ],
            ]);

            $result = $this->offsetService->autoOffsetForContractor(
                $organizationId,
                (int) $validated['contractor_id']
            );

            if (!($result['success'] ?? false)) {
                return AdminResponse::error((string) ($result['message'] ?? trans_message('payments.offsets.auto_error')), 422, $result);
            }

            return AdminResponse::success($result, trans_message('payments.offsets.auto_performed'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('offset.auto.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.offsets.auto_error'), 500);
        }
    }
}
