<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing\Billing;

use App\Exceptions\Billing\CommercialCheckoutConflictException;
use App\Http\Requests\Api\V1\Landing\Billing\CommercialManualPaymentRequest;
use App\Http\Responses\LandingResponse;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\CommercialManualPaymentService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function trans_message;

final class CommercialManualPaymentController
{
    public function __construct(private readonly CommercialManualPaymentService $service) {}

    public function store(CommercialManualPaymentRequest $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $user = $request->user();
            if (! is_numeric($organizationId) || ! $user instanceof User) {
                return LandingResponse::error(
                    trans_message('landing.organization_context_missing'),
                    Response::HTTP_FORBIDDEN,
                );
            }
            $result = $this->service->create(
                Organization::query()->findOrFail((int) $organizationId),
                $user,
                (string) $request->validated('client_idempotency_key'),
            );
            $status = ($result['_created'] ?? false) ? Response::HTTP_CREATED : Response::HTTP_OK;
            unset($result['_created']);

            return LandingResponse::success(
                $result,
                trans_message('billing.renewal.manual_payment_created'),
                $status,
            );
        } catch (CommercialCheckoutConflictException $exception) {
            return LandingResponse::error(
                trans_message('billing.renewal.manual_payment_conflict'),
                Response::HTTP_CONFLICT,
            );
        } catch (ConnectionException|RequestException $exception) {
            Log::warning('Commercial manual payment provider request failed.', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'exception' => $exception::class,
            ]);

            return LandingResponse::error(
                trans_message('billing.checkout.provider_unavailable'),
                Response::HTTP_BAD_GATEWAY,
            );
        } catch (Throwable $exception) {
            Log::error('Commercial manual payment failed.', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);

            return LandingResponse::error(
                trans_message('billing.renewal.manual_payment_failed'),
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }
}
