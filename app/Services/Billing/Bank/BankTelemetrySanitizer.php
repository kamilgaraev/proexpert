<?php

declare(strict_types=1);

namespace App\Services\Billing\Bank;

use Sentry\Event;

final class BankTelemetrySanitizer
{
    public static function sanitize(Event $event): Event
    {
        $request = $event->getRequest();
        $url = (string) ($request['url'] ?? '');
        if (parse_url($url, PHP_URL_PATH) === '/api/v1/webhooks/tochka') {
            $event->setRequest(['url' => explode('?', $url, 2)[0], 'method' => $request['method'] ?? 'POST']);
        }

        return $event;
    }
}
