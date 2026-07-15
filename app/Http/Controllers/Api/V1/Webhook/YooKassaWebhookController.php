<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Contracts\Billing\CommercialWebhookProcessor;
use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Services\Billing\YooKassaWebhookPayloadValidator;
use App\Services\Billing\YooKassaWebhookSourceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

use function trans_message;

final class YooKassaWebhookController extends Controller
{
    public function __construct(
        private readonly YooKassaWebhookSourceResolver $sourceResolver,
        private readonly YooKassaWebhookPayloadValidator $validator,
        private readonly CommercialWebhookProcessor $processor,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $sourceIp = $this->sourceResolver->resolve($request);

        if ($sourceIp === null) {
            return LandingResponse::error(trans_message('billing.webhook.forbidden'), 403);
        }

        try {
            $notification = $this->validator->validate($request->json()->all());
        } catch (InvalidArgumentException $exception) {
            return LandingResponse::error(trans_message('billing.webhook.invalid'), 422);
        }

        try {
            $result = $this->processor->process($notification, $sourceIp);

            return LandingResponse::success(['result' => $result]);
        } catch (Throwable $exception) {
            Log::error('YooKassa webhook processing failed', [
                'event' => $notification->event,
                'object_id' => $notification->objectId,
                'source_ip' => $sourceIp,
                'exception' => $exception::class,
            ]);

            return LandingResponse::error(trans_message('billing.webhook.retry'), 503);
        }
    }
}
