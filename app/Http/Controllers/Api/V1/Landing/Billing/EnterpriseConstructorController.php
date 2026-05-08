<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing\Billing;

use App\DataTransferObjects\Billing\EnterpriseConstructorSelection;
use App\Exceptions\Billing\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Services\Billing\EnterpriseConstructorCheckoutService;
use App\Services\Billing\EnterpriseConstructorPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

final class EnterpriseConstructorController extends Controller
{
    public function __construct(
        private readonly EnterpriseConstructorPricingService $pricingService,
        private readonly EnterpriseConstructorCheckoutService $checkoutService,
    ) {
    }

    public function preview(Request $request): JsonResponse
    {
        try {
            $validator = $this->validator($request);

            if ($validator->fails()) {
                return LandingResponse::error(
                    trans_message('billing.enterprise_constructor.validation_error'),
                    422
                );
            }

            $preview = $this->pricingService->preview(
                EnterpriseConstructorSelection::fromArray($validator->validated())
            );

            return LandingResponse::success($preview, $preview['message']);
        } catch (\Throwable $exception) {
            Log::error('billing.enterprise_constructor.preview_failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'request' => $request->except(['password', 'token']),
                'exception' => $exception,
            ]);

            return LandingResponse::error(trans_message('billing.enterprise_constructor.preview_error'), 500);
        }
    }

    public function checkout(Request $request): JsonResponse
    {
        try {
            $validator = $this->validator($request);

            if ($validator->fails()) {
                return LandingResponse::error(
                    trans_message('billing.enterprise_constructor.validation_error'),
                    422
                );
            }

            $organizationId = $request->user()?->current_organization_id;

            if (! $organizationId) {
                return LandingResponse::error(
                    trans_message('billing.enterprise_constructor.organization_not_found'),
                    404
                );
            }

            $result = $this->checkoutService->checkout(
                (int) $organizationId,
                EnterpriseConstructorSelection::fromArray($validator->validated())
            );

            if (! $result['success']) {
                return LandingResponse::error(
                    $result['message'],
                    (int) ($result['status_code'] ?? 422),
                    ['preview' => $result['preview']]
                );
            }

            return LandingResponse::success([
                'subscription' => $result['subscription'],
                'preview' => $result['preview'],
                'balance' => $result['balance'],
                'module_sync' => $result['module_sync'],
            ], $result['message']);
        } catch (InsufficientBalanceException) {
            return LandingResponse::error(
                trans_message('billing.enterprise_constructor.insufficient_balance'),
                402
            );
        } catch (\Throwable $exception) {
            Log::error('billing.enterprise_constructor.checkout_failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'request' => $request->except(['password', 'token']),
                'exception' => $exception,
            ]);

            return LandingResponse::error(trans_message('billing.enterprise_constructor.checkout_error'), 500);
        }
    }

    private function validator(Request $request): \Illuminate\Validation\Validator
    {
        return Validator::make($request->all(), [
            'users' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'additional_organizations' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'extra_storage_units' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'extended_ai' => ['sometimes', 'boolean'],
            'priority_support' => ['sometimes', 'boolean'],
            'needs_integrations' => ['sometimes', 'boolean'],
            'needs_migration' => ['sometimes', 'boolean'],
            'needs_sla' => ['sometimes', 'boolean'],
            'more_than_250_users' => ['sometimes', 'boolean'],
        ]);
    }
}
