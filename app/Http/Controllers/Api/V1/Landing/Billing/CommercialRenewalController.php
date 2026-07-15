<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing\Billing;

use App\Http\Responses\LandingResponse;
use App\Services\Billing\CommercialRenewalStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function trans_message;

final class CommercialRenewalController
{
    public function __construct(private readonly CommercialRenewalStateService $service) {}

    public function show(Request $request): JsonResponse
    {
        return $this->respond($request, false);
    }

    public function disable(Request $request): JsonResponse
    {
        return $this->respond($request, true);
    }

    private function respond(Request $request, bool $disable): JsonResponse
    {
        try {
            $id = $request->attributes->get('current_organization_id');
            if (! is_numeric($id)) {
                return LandingResponse::error(trans_message('landing.organization_context_missing'), Response::HTTP_FORBIDDEN);
            }
            $data = $disable ? $this->service->disable((int) $id) : $this->service->state((int) $id);

            return LandingResponse::success($data, trans_message($disable ? 'billing.renewal.disabled' : 'billing.renewal.loaded'));
        } catch (Throwable $exception) {
            Log::error('Commercial renewal state request failed.', ['organization_id' => $request->attributes->get('current_organization_id'), 'exception' => $exception::class]);

            return LandingResponse::error(trans_message('billing.renewal.failed'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
