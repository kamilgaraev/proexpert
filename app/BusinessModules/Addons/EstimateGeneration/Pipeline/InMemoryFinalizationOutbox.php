<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use DateTimeImmutable;

final class InMemoryFinalizationOutbox implements FinalizationOutbox
{
    /** @var array<string, array{id: int, event: FinalizationEvent, status: string, token: ?string, lease: ?DateTimeImmutable, available: DateTimeImmutable, attempt: int}> */
    private array $events = [];

    public function enqueue(FinalizationEvent $event, DateTimeImmutable $availableAt): void
    {
        $this->events[$event->idempotencyKey] ??= [
            'id' => count($this->events) + 1,
            'event' => $event,
            'status' => 'pending',
            'token' => null,
            'lease' => null,
            'available' => $availableAt,
            'attempt' => 0,
        ];
    }

    public function claim(DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt): ?FinalizationClaim
    {
        foreach ($this->events as &$row) {
            if ($row['status'] === 'delivered' || $row['available'] > $now) {
                continue;
            }
            if ($row['status'] === 'delivering' && $row['lease'] !== null && $row['lease'] > $now) {
                continue;
            }
            $row['status'] = 'delivering';
            $row['token'] = bin2hex(random_bytes(16));
            $row['lease'] = $leaseExpiresAt;
            $row['attempt']++;

            return new FinalizationClaim($row['id'], $row['event'], $row['token'], $row['attempt']);
        }
        unset($row);

        return null;
    }

    public function complete(FinalizationClaim $claim, DateTimeImmutable $deliveredAt): bool
    {
        $row = &$this->events[$claim->event->idempotencyKey];
        if (($row['status'] ?? null) !== 'delivering' || ! hash_equals((string) $row['token'], $claim->claimToken)) {
            return false;
        }
        $row['status'] = 'delivered';
        $row['token'] = null;
        $row['lease'] = null;

        return true;
    }

    public function release(FinalizationClaim $claim, DateTimeImmutable $availableAt): bool
    {
        $row = &$this->events[$claim->event->idempotencyKey];
        if (($row['status'] ?? null) !== 'delivering' || ! hash_equals((string) $row['token'], $claim->claimToken)) {
            return false;
        }
        $row['status'] = 'pending';
        $row['token'] = null;
        $row['lease'] = null;
        $row['available'] = $availableAt;

        return true;
    }
}
