<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Http\Controllers;

use App\BusinessModules\Core\MultiOrganization\Requests\LegacyHoldingReportRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Landing\Report\ConsolidatedActResource;
use App\Http\Resources\Api\V1\Landing\Report\ConsolidatedContractResource;
use App\Http\Resources\Billing\BalanceTransactionResource;
use App\Http\Responses\LandingResponse;
use App\Services\Landing\HoldingReportService;
use App\Services\Landing\MultiOrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function trans_message;

class HoldingLegacyReportsController extends Controller
{
    public function __construct(
        private readonly MultiOrganizationService $multiOrgService,
        private readonly HoldingReportService $holdingReportService
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        try {
            return LandingResponse::success(
                $this->multiOrgService->getHoldingDashboard($this->currentOrganizationId($request))
            );
        } catch (Throwable $e) {
            return $this->fail($e, $request, 'dashboard');
        }
    }

    public function contracts(LegacyHoldingReportRequest $request): JsonResponse
    {
        try {
            $contracts = $this->holdingReportService->getConsolidatedContracts(
                $this->accessibleOrganizationIds($request),
                $request->validated()
            );

            return LandingResponse::success(ConsolidatedContractResource::collection($contracts));
        } catch (Throwable $e) {
            return $this->fail($e, $request, 'contracts');
        }
    }

    public function contractsSummary(LegacyHoldingReportRequest $request): JsonResponse
    {
        try {
            return LandingResponse::success($this->holdingReportService->getContractsSummary(
                $this->accessibleOrganizationIds($request),
                $request->validated()
            ));
        } catch (Throwable $e) {
            return $this->fail($e, $request, 'contractsSummary');
        }
    }

    public function acts(LegacyHoldingReportRequest $request): JsonResponse
    {
        try {
            $acts = $this->holdingReportService->getConsolidatedActs(
                $this->accessibleOrganizationIds($request),
                $request->validated()
            );

            return LandingResponse::success(ConsolidatedActResource::collection($acts));
        } catch (Throwable $e) {
            return $this->fail($e, $request, 'acts');
        }
    }

    public function movements(LegacyHoldingReportRequest $request): JsonResponse
    {
        try {
            $movements = $this->holdingReportService->getMoneyMovements(
                $this->accessibleOrganizationIds($request),
                $request->validated()
            );

            return LandingResponse::success(BalanceTransactionResource::collection($movements));
        } catch (Throwable $e) {
            return $this->fail($e, $request, 'movements');
        }
    }

    private function accessibleOrganizationIds(Request $request): array
    {
        return $this->multiOrgService->getAccessibleOrganizations($request->user())
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function currentOrganizationId(Request $request): int
    {
        return (int) ($request->attributes->get('current_organization_id') ?? $request->user()->current_organization_id);
    }

    private function fail(Throwable $e, Request $request, string $action): JsonResponse
    {
        Log::error("[HoldingLegacyReportsController.{$action}] Failed", [
            'message' => $e->getMessage(),
            'organization_id' => $request->attributes->get('current_organization_id'),
            'user_id' => $request->user()?->id,
        ]);

        $status = $e->getCode() >= 400 && $e->getCode() < 600
            ? $e->getCode()
            : Response::HTTP_BAD_REQUEST;

        return LandingResponse::error(trans_message('errors.business_logic_error'), $status);
    }
}
