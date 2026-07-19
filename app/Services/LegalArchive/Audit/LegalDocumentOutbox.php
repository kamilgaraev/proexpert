<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Audit;

use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditIntegrityService;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRedactor;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentOutboxMessage;
use App\Jobs\LegalArchive\PublishLegalDocumentOutboxMessage;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

final class LegalDocumentOutbox
{
    public function __construct(
        private readonly ImmutableAuditRedactor $redactor = new ImmutableAuditRedactor,
        private readonly ImmutableAuditIntegrityService $integrity = new ImmutableAuditIntegrityService,
        private readonly bool $dispatchJobs = true,
        private readonly int $maximumAttempts = 8,
        private readonly int $claimTimeoutSeconds = 600,
        private readonly ?ConnectionInterface $connection = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function enqueue(
        string $event,
        string $aggregateType,
        string $aggregateId,
        array $payload,
        string $idempotencyKey,
    ): LegalDocumentOutboxMessage {
        $organizationId = filter_var($payload['organization_id'] ?? null, FILTER_VALIDATE_INT);
        if ($organizationId === false || $organizationId < 1) {
            throw new DomainException('legal_document_outbox_organization_required');
        }

        foreach ([$event, $aggregateType, $aggregateId, $idempotencyKey] as $value) {
            if (trim($value) === '') {
                throw new DomainException('legal_document_outbox_identity_required');
            }
        }

        $redacted = $this->redactor->redact($payload);
        $canonicalJson = $this->integrity->canonicalJson($redacted);
        $canonicalPayload = json_decode($canonicalJson, true, flags: JSON_THROW_ON_ERROR);
        $payloadHash = hash('sha256', $canonicalJson);
        $created = false;

        try {
            $message = $this->database()->transaction(function () use (
                $organizationId,
                $aggregateType,
                $aggregateId,
                $event,
                $canonicalPayload,
                $payloadHash,
                $idempotencyKey,
                &$created,
            ): LegalDocumentOutboxMessage {
                $existing = $this->findExisting($organizationId, $aggregateType, $aggregateId, $idempotencyKey);
                if ($existing instanceof LegalDocumentOutboxMessage) {
                    $this->assertSameMessage($existing, $event, $payloadHash);

                    return $existing;
                }

                $created = true;

                return LegalDocumentOutboxMessage::query()->create([
                    'organization_id' => $organizationId,
                    'aggregate_type' => $aggregateType,
                    'aggregate_id' => $aggregateId,
                    'event' => $event,
                    'payload' => $canonicalPayload,
                    'payload_hash' => $payloadHash,
                    'idempotency_key' => $idempotencyKey,
                    'attempts' => 0,
                    'available_at' => now(),
                ]);
            }, 3);
        } catch (QueryException $exception) {
            $message = $this->findExisting($organizationId, $aggregateType, $aggregateId, $idempotencyKey);
            if (! $message instanceof LegalDocumentOutboxMessage) {
                throw $exception;
            }
            $this->assertSameMessage($message, $event, $payloadHash);
            $created = false;
        }

        if ($created && $this->dispatchJobs) {
            $this->database()->afterCommit(static function () use ($message): void {
                PublishLegalDocumentOutboxMessage::dispatch((string) $message->id)->afterCommit();
            });
        }

        return $message;
    }

    public function publish(string $messageId, LegalDocumentOutboxPublisher $publisher): LegalDocumentOutboxPublishResult
    {
        $claim = $this->claim($messageId);
        if (! $claim['message'] instanceof LegalDocumentOutboxMessage) {
            return new LegalDocumentOutboxPublishResult($claim['status'], $claim['retry_at']);
        }

        $message = $claim['message'];
        $claimToken = (string) $message->claim_token;

        try {
            $publisher->publish($message);
        } catch (Throwable $exception) {
            return $this->markFailure($message, $claimToken, $exception);
        }

        $updated = LegalDocumentOutboxMessage::query()
            ->whereKey($message->id)
            ->where('claim_token', $claimToken)
            ->whereNull('published_at')
            ->update([
                'published_at' => now(),
                'claim_token' => null,
                'claimed_at' => null,
                'last_error' => null,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            return new LegalDocumentOutboxPublishResult('claim_lost');
        }

        return new LegalDocumentOutboxPublishResult('published');
    }

    public function reconciliationCandidates(int $organizationId, int $limit = 100): Collection
    {
        return LegalDocumentOutboxMessage::query()
            ->where('organization_id', $organizationId)
            ->whereNull('published_at')
            ->where(function ($query): void {
                $query->whereNotNull('reconciliation_required_at')
                    ->orWhere(function ($stale): void {
                        $stale->whereNotNull('claimed_at')
                            ->where('claimed_at', '<=', now()->subSeconds($this->claimTimeoutSeconds));
                    });
            })
            ->orderBy('created_at')
            ->limit(max(1, min($limit, 1000)))
            ->get();
    }

    /** @return Collection<int, string> */
    public function pendingMessageIdsForDispatch(int $limit = 100): Collection
    {
        $staleBefore = now()->subSeconds($this->claimTimeoutSeconds);

        return LegalDocumentOutboxMessage::query()
            ->whereNull('published_at')
            ->whereNull('dead_lettered_at')
            ->where('available_at', '<=', now())
            ->where(function ($query) use ($staleBefore): void {
                $query->whereNull('claim_token')
                    ->orWhereNull('claimed_at')
                    ->orWhere('claimed_at', '<=', $staleBefore);
            })
            ->orderBy('created_at')
            ->limit(max(1, min($limit, 1000)))
            ->pluck('id');
    }

    public function retryReconciled(int $organizationId, string $messageId): bool
    {
        $requeued = $this->database()->transaction(function () use ($organizationId, $messageId): bool {
            $message = LegalDocumentOutboxMessage::query()
                ->whereKey($messageId)
                ->where('organization_id', $organizationId)
                ->whereNull('published_at')
                ->whereNotNull('reconciliation_required_at')
                ->lockForUpdate()
                ->first();
            if (! $message instanceof LegalDocumentOutboxMessage) {
                return false;
            }

            $message->forceFill([
                'attempts' => 0,
                'available_at' => now(),
                'claim_token' => null,
                'claimed_at' => null,
                'dead_lettered_at' => null,
                'reconciliation_required_at' => null,
                'updated_at' => now(),
            ])->save();

            return true;
        }, 3);

        if ($requeued && $this->dispatchJobs) {
            $this->database()->afterCommit(static function () use ($messageId): void {
                PublishLegalDocumentOutboxMessage::dispatch($messageId)->afterCommit();
            });
        }

        return $requeued;
    }

    private function claim(string $messageId): array
    {
        return $this->database()->transaction(function () use ($messageId): array {
            $message = LegalDocumentOutboxMessage::query()->whereKey($messageId)->lockForUpdate()->first();
            if (! $message instanceof LegalDocumentOutboxMessage) {
                return ['status' => 'not_found', 'message' => null, 'retry_at' => null];
            }
            if ($message->published_at !== null) {
                return ['status' => 'already_published', 'message' => null, 'retry_at' => null];
            }
            if ($message->dead_lettered_at !== null) {
                return ['status' => 'dead_lettered', 'message' => null, 'retry_at' => null];
            }

            $now = now();
            if ($message->available_at instanceof Carbon && $message->available_at->isAfter($now)) {
                return ['status' => 'not_available', 'message' => null, 'retry_at' => $message->available_at];
            }
            if (
                $message->claim_token !== null
                && $message->claimed_at instanceof Carbon
                && $message->claimed_at->isAfter($now->copy()->subSeconds($this->claimTimeoutSeconds))
            ) {
                return [
                    'status' => 'busy',
                    'message' => null,
                    'retry_at' => $message->claimed_at->copy()->addSeconds($this->claimTimeoutSeconds),
                ];
            }

            $message->forceFill([
                'claim_token' => (string) Str::uuid(),
                'claimed_at' => $now,
                'attempts' => ((int) $message->attempts) + 1,
                'updated_at' => $now,
            ])->save();

            return ['status' => 'claimed', 'message' => $message->refresh(), 'retry_at' => null];
        }, 3);
    }

    private function markFailure(
        LegalDocumentOutboxMessage $message,
        string $claimToken,
        Throwable $exception,
    ): LegalDocumentOutboxPublishResult {
        $deadLettered = (int) $message->attempts >= max(1, $this->maximumAttempts);
        $retryAt = $deadLettered ? null : now()->addSeconds($this->backoffSeconds((int) $message->attempts));
        $updated = LegalDocumentOutboxMessage::query()
            ->whereKey($message->id)
            ->where('claim_token', $claimToken)
            ->whereNull('published_at')
            ->update([
                'available_at' => $retryAt ?? $message->available_at,
                'last_error' => $exception::class,
                'claim_token' => null,
                'claimed_at' => null,
                'dead_lettered_at' => $deadLettered ? now() : null,
                'reconciliation_required_at' => $deadLettered ? now() : null,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            $this->logger?->warning('legal_document_outbox.claim_lost', [
                'message_id' => $message->id,
                'organization_id' => $message->organization_id,
                'attempts' => $message->attempts,
            ]);

            return new LegalDocumentOutboxPublishResult('claim_lost');
        }

        $this->logger?->error('legal_document_outbox.publish_failed', [
            'message_id' => $message->id,
            'organization_id' => $message->organization_id,
            'aggregate_type' => $message->aggregate_type,
            'aggregate_id' => $message->aggregate_id,
            'event' => $message->event,
            'attempts' => $message->attempts,
            'error_type' => $exception::class,
            'dead_lettered' => $deadLettered,
        ]);

        return new LegalDocumentOutboxPublishResult(
            $deadLettered ? 'dead_lettered' : 'retry_scheduled',
            $retryAt,
        );
    }

    private function backoffSeconds(int $attempt): int
    {
        return min(3600, 15 * (2 ** max(0, $attempt - 1)));
    }

    private function findExisting(
        int $organizationId,
        string $aggregateType,
        string $aggregateId,
        string $idempotencyKey,
    ): ?LegalDocumentOutboxMessage {
        return LegalDocumentOutboxMessage::query()
            ->where('organization_id', $organizationId)
            ->where('aggregate_type', $aggregateType)
            ->where('aggregate_id', $aggregateId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function assertSameMessage(
        LegalDocumentOutboxMessage $message,
        string $event,
        string $payloadHash,
    ): void {
        if ($message->event !== $event || ! hash_equals((string) $message->payload_hash, $payloadHash)) {
            throw new DomainException('legal_document_outbox_idempotency_conflict');
        }
    }

    private function database(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }
}
