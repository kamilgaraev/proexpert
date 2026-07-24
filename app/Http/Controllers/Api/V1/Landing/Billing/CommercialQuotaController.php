<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing\Billing;

use App\Http\Requests\Api\V1\Landing\Billing\CommercialResourceAddonQuoteRequest;
use App\Http\Responses\LandingResponse;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\CommercialQuotaService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function trans_message;

class CommercialQuotaController
{
    public function __construct(
        private readonly CommercialQuotaService $quotaService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        try {
            return LandingResponse::success(
                $this->quotaService->getQuotaSummary($this->organization($request)),
                trans_message('billing.quota.loaded'),
            );
        } catch (Throwable $exception) {
            return $this->failure($request, $exception, 'show');
        }
    }

    public function quote(CommercialResourceAddonQuoteRequest $request): JsonResponse
    {
        try {
            return LandingResponse::success(
                $this->quotaService->calculateResourceAddonQuote(
                    $this->organization($request),
                    $request->validated('resources'),
                ),
                trans_message('billing.quota.quote_ready'),
            );
        } catch (InvalidArgumentException $exception) {
            return LandingResponse::error(
                trans_message('billing.quota.invalid'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        } catch (Throwable $exception) {
            return $this->failure($request, $exception, 'quote');
        }
    }

    private function organization(Request $request): Organization
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new ModelNotFoundException;
        }

        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        if (! is_numeric($organizationId)) {
            throw new ModelNotFoundException;
        }

        return Organization::query()->findOrFail((int) $organizationId);
    }

    private function failure(Request $request, Throwable $exception, string $operation): JsonResponse
    {
        Log::error('Commercial quota API operation failed.', [
            'operation' => $operation,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'user_id' => $request->user()?->id,
            'exception' => $exception::class,
            'error' => $exception->getMessage(),
        ]);

        return LandingResponse::error(
            trans_message('billing.quota.failed'),
            Response::HTTP_INTERNAL_SERVER_ERROR,
        );
    }
}
