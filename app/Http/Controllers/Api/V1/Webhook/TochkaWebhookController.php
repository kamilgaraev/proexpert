<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Services\Billing\Bank\TochkaWebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class TochkaWebhookController
{
    public function __invoke(Request $request, TochkaWebhookService $service): Response
    {
        if (config('bank_billing.enabled') !== true) {
            return new Response('Unavailable', 503);
        }
        try {
            $service->receive($request->getContent());

            return new Response('OK', 200);
        } catch (InvalidArgumentException) {
            return new Response('Invalid notification', 401);
        } catch (Throwable $exception) {
            Log::error('Bank notification persistence failed.', ['exception' => $exception::class]);

            return new Response('Retry', 503);
        }
    }
}
