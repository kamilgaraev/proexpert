<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing\Billing;

use App\Http\Controllers\Controller;
use App\Http\Resources\Billing\BalanceTransactionResource;
use App\Http\Resources\Billing\OrganizationBalanceResource;
use App\Http\Responses\LandingResponse;
use App\Interfaces\Billing\BalanceServiceInterface;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function trans_message;

class BalanceController extends Controller
{
    public function __construct(
        protected BalanceServiceInterface $balanceService
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        try {
            $organization = $this->currentOrganization($request);

            if (! $organization) {
                return LandingResponse::error(
                    trans_message('billing.balance.organization_not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            $balance = Cache::remember(
                "organization_balance_{$organization->id}",
                30,
                fn () => $this->balanceService->getOrCreateOrganizationBalance($organization)
            );

            return LandingResponse::success(
                new OrganizationBalanceResource($balance),
                trans_message('billing.balance.loaded')
            );
        } catch (Throwable $exception) {
            Log::error('landing.billing.balance.load_failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return LandingResponse::error(
                trans_message('billing.balance.load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function getTransactions(Request $request): JsonResponse
    {
        try {
            $organization = $this->currentOrganization($request);

            if (! $organization) {
                return LandingResponse::error(
                    trans_message('billing.balance.organization_not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            $balance = $this->balanceService->getOrCreateOrganizationBalance($organization);
            $perPage = max(1, min((int) $request->input('limit', 15), 100));
            $transactions = $balance->transactions()->latest()->paginate($perPage);

            return LandingResponse::success(
                BalanceTransactionResource::collection($transactions),
                trans_message('billing.balance.transactions_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('landing.billing.balance.transactions_load_failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return LandingResponse::error(
                trans_message('billing.balance.transactions_load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    private function currentOrganization(Request $request): ?Organization
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user?->current_organization_id;

        if (! $user || ! $organizationId) {
            return null;
        }

        if ((int) $organizationId !== (int) $user->current_organization_id) {
            return null;
        }

        return Organization::find((int) $organizationId);
    }
}
