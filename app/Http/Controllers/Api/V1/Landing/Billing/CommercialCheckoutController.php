<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing\Billing;

use App\Exceptions\Billing\CommercialCheckoutAmountException;
use App\Exceptions\Billing\CommercialCheckoutConflictException;
use App\Exceptions\Billing\StaleCommercialOfferException;
use App\Http\Requests\Api\V1\Landing\Billing\CommercialCheckoutRequest;
use App\Http\Responses\LandingResponse;
use App\Models\Organization;
use App\Services\Billing\CommercialCheckoutService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Tymon\JWTAuth\Facades\JWTAuth;

use function trans_message;

class CommercialCheckoutController
{
    public function __construct(
        private readonly CommercialCheckoutService $checkoutService,
    ) {}

    public function store(CommercialCheckoutRequest $request): JsonResponse
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;

            if (! is_numeric($organizationId)) {
                return LandingResponse::error(
                    trans_message('landing.organization_context_missing'),
                    Response::HTTP_FORBIDDEN,
                );
            }

            $organization = Organization::query()->findOrFail((int) $organizationId);
            $result = $this->checkoutService->checkout($organization, $user, $request->validated());
            $status = ($result['_created'] ?? false) ? Response::HTTP_CREATED : Response::HTTP_OK;
            unset($result['_created']);

            return LandingResponse::success(
                $result,
                trans_message('billing.checkout.created'),
                $status,
            );
        } catch (StaleCommercialOfferException|CommercialCheckoutConflictException $exception) {
            return LandingResponse::error(
                trans_message('billing.checkout.conflict'),
                Response::HTTP_CONFLICT,
            );
        } catch (CommercialCheckoutAmountException|InvalidArgumentException $exception) {
            return LandingResponse::error(
                trans_message('billing.checkout.invalid'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        } catch (ConnectionException|RequestException $exception) {
            Log::warning('Commercial checkout provider request failed.', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'exception' => $exception::class,
            ]);

            return LandingResponse::error(
                trans_message('billing.checkout.provider_unavailable'),
                Response::HTTP_BAD_GATEWAY,
            );
        } catch (Throwable $exception) {
            Log::error('Commercial checkout failed.', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);

            return LandingResponse::error(
                trans_message('billing.checkout.failed'),
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }
}
