<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class RustorePushTransport implements PushProviderTransport
{
    public function send(string $token, string $title, string $body, array $data): ?string
    {
        $projectId = (string) config('services.rustore.project_id');
        $serviceToken = (string) config('services.rustore.service_token');

        if ($projectId === '' || $serviceToken === '') {
            throw new RuntimeException('RuStore push credentials are not configured');
        }

        $response = Http::withToken($serviceToken)
            ->acceptJson()
            ->asJson()
            ->timeout(10)
            ->post('https://vkpns.rustore.ru/v1/projects/'.rawurlencode($projectId).'/messages:send', [
                'message' => [
                    'token' => $token,
                    'data' => $data,
                    'notification' => ['title' => $title, 'body' => $body],
                ],
            ]);

        if ($response->successful()) {
            $messageId = $response->json('name');

            return is_string($messageId) ? $messageId : null;
        }

        $status = (string) $response->json('error.status', 'HTTP_'.$response->status());
        if (preg_match('/^[A-Z0-9_]{1,64}$/', $status) !== 1) {
            $status = 'HTTP_'.$response->status();
        }
        $errorMessage = $response->json('error.message');
        $invalidRegistrationToken = $response->status() === 400
            && $status === 'INVALID_ARGUMENT'
            && is_string($errorMessage)
            && str_contains(mb_strtolower($errorMessage), 'registration token');
        $invalid = $status === 'NOT_FOUND' || $status === 'UNREGISTERED' || $invalidRegistrationToken;
        throw new PushDeliveryException('RuStore push failed: '.$status, $invalid);
    }
}
