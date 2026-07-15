<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Services;

use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use DomainException;

final class NotificationTargetResolver
{
    public function resolve(array $interfaces, array $data): array
    {
        $candidates = $interfaces !== []
            ? $interfaces
            : [$data['interface'] ?? null];
        $resolved = [];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                throw new DomainException('Notification interface must be a non-empty string');
            }

            $interface = NotificationInterface::tryFrom(trim($candidate));

            if ($interface === null) {
                throw new DomainException("Unknown notification interface: {$candidate}");
            }

            $resolved[$interface->value] = $interface;
        }

        if ($resolved === []) {
            throw new DomainException('At least one notification interface is required');
        }

        return array_values($resolved);
    }
}
