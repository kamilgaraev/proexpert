<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Services;

interface PushProviderTransport
{
    /** @param array<string, string> $data */
    public function send(string $token, string $title, string $body, array $data): ?string;
}
