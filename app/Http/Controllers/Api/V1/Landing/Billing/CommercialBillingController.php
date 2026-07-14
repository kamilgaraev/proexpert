<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing\Billing;

use App\Exceptions\Billing\CommercialBillingConflictException;
use App\Exceptions\Billing\StaleCommercialOfferException;
use App\Http\Requests\Api\V1\Landing\Billing\CommercialContourScheduleRequest;
use App\Http\Requests\Api\V1\Landing\Billing\CommercialHistoryRequest;
use App\Http\Requests\Api\V1\Landing\Billing\CommercialQuoteRequest;
use App\Http\Responses\LandingResponse;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\CommercialBillingQueryService;
use App\Services\Billing\CommercialContourChangeService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function trans_message;

final class CommercialBillingController
{
    public function __construct(
        private readonly CommercialBillingQueryService $billing,
        private readonly CommercialContourChangeService $contourChanges,
    ) {}

    public function quote(CommercialQuoteRequest $request): JsonResponse
    {
        try {
            return LandingResponse::success(
                $this->billing->quote($this->organization($request), $request->validated()),
                trans_message('billing.commercial.quote_ready'),
            );
        } catch (CommercialBillingConflictException $exception) {
            return LandingResponse::error(
                trans_message('billing.commercial.grace_blocked'),
                Response::HTTP_CONFLICT,
            );
        } catch (InvalidArgumentException $exception) {
            return LandingResponse::error(
                trans_message('billing.commercial.invalid'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        } catch (Throwable $exception) {
            return $this->failure($request, $exception, 'quote');
        }
    }

    public function show(Request $request, string $publicId): JsonResponse
    {
        try {
            return LandingResponse::success(
                $this->billing->order($this->organization($request), $publicId),
                trans_message('billing.commercial.order_loaded'),
            );
        } catch (ModelNotFoundException $exception) {
            return LandingResponse::error(
                trans_message('billing.commercial.order_not_found'),
                Response::HTTP_NOT_FOUND,
            );
        } catch (Throwable $exception) {
            return $this->failure($request, $exception, 'order', ['order_id' => $publicId]);
        }
    }

    public function history(CommercialHistoryRequest $request): JsonResponse
    {
        try {
            $paginator = $this->billing->history(
                $this->organization($request),
                (int) ($request->validated('per_page') ?? 20),
            );

            return LandingResponse::paginated(
                $paginator->items(),
                [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'last_page' => $paginator->lastPage(),
                    'total' => $paginator->total(),
                ],
                trans_message('billing.commercial.history_loaded'),
                links: [
                    'first' => $paginator->url(1),
                    'last' => $paginator->url($paginator->lastPage()),
                    'prev' => $paginator->previousPageUrl(),
                    'next' => $paginator->nextPageUrl(),
                ],
            );
        } catch (Throwable $exception) {
            return $this->failure($request, $exception, 'history');
        }
    }

    public function schedule(CommercialContourScheduleRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (! $user instanceof User) {
                throw new ModelNotFoundException;
            }
            $result = $this->contourChanges->schedule(
                $this->organization($request),
                $user,
                $request->validated(),
            );
            $status = ($result['_created'] ?? false) ? Response::HTTP_CREATED : Response::HTTP_OK;
            unset($result['_created']);

            return LandingResponse::success(
                $result,
                trans_message('billing.commercial.change_scheduled'),
                $status,
            );
        } catch (CommercialBillingConflictException|StaleCommercialOfferException $exception) {
            return LandingResponse::error(
                trans_message('billing.commercial.change_conflict'),
                Response::HTTP_CONFLICT,
            );
        } catch (InvalidArgumentException|ModelNotFoundException $exception) {
            return LandingResponse::error(
                trans_message('billing.commercial.invalid'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        } catch (Throwable $exception) {
            return $this->failure($request, $exception, 'schedule');
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

    private function failure(
        Request $request,
        Throwable $exception,
        string $operation,
        array $context = [],
    ): JsonResponse {
        Log::error('Commercial billing API operation failed.', $context + [
            'operation' => $operation,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'user_id' => $request->user()?->id,
            'exception' => $exception::class,
            'error' => $exception->getMessage(),
        ]);

        return LandingResponse::error(
            trans_message('billing.commercial.failed'),
            Response::HTTP_INTERNAL_SERVER_ERROR,
        );
    }
}
