<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;

final readonly class EloquentFinalizationOutbox implements FinalizationOutbox
{
    public function __construct(private Connection $database) {}

    public function enqueue(FinalizationEvent $event, DateTimeImmutable $availableAt): void
    {
        $this->database->table('estimate_generation_finalization_outbox')->insertOrIgnore([
            'organization_id' => $event->organizationId,
            'project_id' => $event->projectId,
            'session_id' => $event->sessionId,
            'generation_attempt_id' => $event->generationAttemptId,
            'event_type' => $event->type,
            'idempotency_key' => $event->idempotencyKey,
            'status' => 'pending',
            'attempt_count' => 0,
            'available_at' => $availableAt,
            'created_at' => $availableAt,
            'updated_at' => $availableAt,
        ]);
    }

    public function claim(DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt): ?FinalizationClaim
    {
        return $this->database->transaction(function () use ($now, $leaseExpiresAt): ?FinalizationClaim {
            $row = $this->database->table('estimate_generation_finalization_outbox')
                ->where('available_at', '<=', $now)
                ->where(function ($query) use ($now): void {
                    $query->where('status', 'pending')
                        ->orWhere(function ($expired) use ($now): void {
                            $expired->where('status', 'delivering')->where('lease_expires_at', '<=', $now);
                        });
                })
                ->orderBy('available_at')
                ->orderBy('id')
                ->lock('FOR UPDATE SKIP LOCKED')
                ->first();
            if ($row === null) {
                return null;
            }
            $token = (string) Str::uuid();
            $attempt = (int) $row->attempt_count + 1;
            $updated = $this->database->table('estimate_generation_finalization_outbox')
                ->where('id', $row->id)
                ->update([
                    'status' => 'delivering',
                    'claim_token' => $token,
                    'lease_expires_at' => $leaseExpiresAt,
                    'attempt_count' => $attempt,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                return null;
            }

            return new FinalizationClaim(
                (int) $row->id,
                new FinalizationEvent(
                    (int) $row->organization_id,
                    (int) $row->project_id,
                    (int) $row->session_id,
                    (string) $row->generation_attempt_id,
                    (string) $row->event_type,
                    (string) $row->idempotency_key,
                ),
                $token,
                $attempt,
            );
        }, 3);
    }

    public function complete(FinalizationClaim $claim, DateTimeImmutable $deliveredAt): bool
    {
        return $this->database->table('estimate_generation_finalization_outbox')
            ->where('id', $claim->id)
            ->where('status', 'delivering')
            ->where('claim_token', $claim->claimToken)
            ->update([
                'status' => 'delivered',
                'claim_token' => null,
                'lease_expires_at' => null,
                'delivered_at' => $deliveredAt,
                'updated_at' => $deliveredAt,
            ]) === 1;
    }

    public function release(FinalizationClaim $claim, DateTimeImmutable $availableAt): bool
    {
        return $this->database->table('estimate_generation_finalization_outbox')
            ->where('id', $claim->id)
            ->where('status', 'delivering')
            ->where('claim_token', $claim->claimToken)
            ->update([
                'status' => 'pending',
                'claim_token' => null,
                'lease_expires_at' => null,
                'available_at' => $availableAt,
                'updated_at' => $availableAt,
            ]) === 1;
    }
}
