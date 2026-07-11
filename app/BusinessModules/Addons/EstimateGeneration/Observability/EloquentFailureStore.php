<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use DateTimeImmutable;
use Illuminate\Database\Connection;

final readonly class EloquentFailureStore implements FailureStore
{
    public function __construct(private Connection $database) {}

    public function record(FailureData $failure, DateTimeImmutable $seenAt): void
    {
        $this->database->transaction(function () use ($failure, $seenAt): void {
            $identityId = self::deterministicId('identity|'.$failure->fingerprint);
            $identity = $this->identityAttributes($failure, $identityId, $seenAt);
            $this->database->table('estimate_generation_failure_identities')->insertOrIgnore($identity);
            $existingIdentity = $this->database->table('estimate_generation_failure_identities')
                ->where('fingerprint', $failure->fingerprint)->lockForUpdate()->first();
            if ($existingIdentity === null || ! $this->sameIdentity($existingIdentity, $identity)) {
                throw new FailureStoreInvariantViolation('Failure identity collision.');
            }

            $event = [
                'event_id' => strtolower($failure->context->eventId),
                'correlation_id' => strtolower($failure->context->correlationId),
                'failure_id' => $identityId,
                'fingerprint' => $failure->fingerprint,
                'organization_id' => $failure->context->organizationId,
                'project_id' => $failure->context->projectId,
                'session_id' => $failure->context->sessionId,
                'event_type' => 'occurred',
                'attempt' => $failure->context->attempt,
                'safe_context' => json_encode($failure->safeContext, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'resolution_code' => null,
                'resolves_through_sequence' => null,
                'recorded_at' => $seenAt,
            ];
            $this->database->table('estimate_generation_failure_events')->insertOrIgnore($event);
            $existingEvent = $this->database->table('estimate_generation_failure_events')
                ->where('event_id', $event['event_id'])->first();
            if ($existingEvent === null
                || ! hash_equals((string) $existingEvent->fingerprint, $failure->fingerprint)
                || (string) $existingEvent->failure_id !== $identityId
                || (string) $existingEvent->event_type !== 'occurred') {
                throw new FailureStoreInvariantViolation('Failure event collision.');
            }
        }, 3);
    }

    public function resolve(
        FailureContext $context,
        string $fingerprint,
        string $resolutionCode,
        DateTimeImmutable $resolvedAt,
    ): bool {
        $active = $this->activeQuery($context)->where('fingerprint', $fingerprint)->first();
        if ($active === null || $active->resolved_at !== null) {
            return false;
        }

        return $this->recordResolution($context, $active, $resolutionCode, $resolvedAt);
    }

    public function resolveActive(
        FailureContext $context,
        string $resolutionCode,
        DateTimeImmutable $resolvedAt,
    ): int {
        $resolved = 0;
        foreach ($this->activeQuery($context)->whereNull('resolved_at')->get() as $active) {
            $resolved += $this->recordResolution($context, $active, $resolutionCode, $resolvedAt) ? 1 : 0;
        }

        return $resolved;
    }

    private function recordResolution(FailureContext $context, object $active, string $resolutionCode, DateTimeImmutable $at): bool
    {
        return $this->database->transaction(function () use ($context, $active, $resolutionCode, $at): bool {
            $identity = $this->database->table('estimate_generation_failure_identities')
                ->where('id', (string) $active->id)
                ->where('organization_id', $context->organizationId)
                ->where('project_id', $context->projectId)
                ->where('session_id', $context->sessionId)
                ->lockForUpdate()
                ->first();
            if ($identity === null) {
                return false;
            }
            $latestOccurrenceSequence = $this->database->table('estimate_generation_failure_events')
                ->where('failure_id', (string) $active->id)
                ->where('event_type', 'occurred')
                ->max('sequence');
            if ($latestOccurrenceSequence === null) {
                return false;
            }
            if ((int) $latestOccurrenceSequence !== (int) $active->latest_occurrence_sequence) {
                return false;
            }
            $eventId = self::deterministicId(sprintf(
                'resolution|%s|%s|%s',
                $context->eventId,
                (string) $active->fingerprint,
                $resolutionCode,
            ));
            $event = [
                'event_id' => $eventId,
                'correlation_id' => strtolower($context->correlationId),
                'failure_id' => (string) $active->id,
                'fingerprint' => (string) $active->fingerprint,
                'organization_id' => $context->organizationId,
                'project_id' => $context->projectId,
                'session_id' => $context->sessionId,
                'event_type' => 'resolved',
                'attempt' => $context->attempt,
                'safe_context' => '{}',
                'resolution_code' => $resolutionCode,
                'resolves_through_sequence' => (int) $latestOccurrenceSequence,
                'recorded_at' => $at,
            ];
            $inserted = $this->database->table('estimate_generation_failure_events')->insertOrIgnore($event);
            $existing = $this->database->table('estimate_generation_failure_events')->where('event_id', $eventId)->first();
            if ($existing === null
                || (string) $existing->failure_id !== (string) $active->id
                || (int) $existing->resolves_through_sequence !== (int) $latestOccurrenceSequence) {
                throw new FailureStoreInvariantViolation('Failure resolution event collision.');
            }

            return $inserted === 1;
        }, 3);
    }

    private function activeQuery(FailureContext $context): \Illuminate\Database\Query\Builder
    {
        return $this->database->table('estimate_generation_failures')
            ->where('organization_id', $context->organizationId)
            ->where('project_id', $context->projectId)
            ->where('session_id', $context->sessionId)
            ->where('stage', $context->stage->value)
            ->where('operation', $context->operation)
            ->where(function ($query) use ($context): void {
                $context->documentId === null ? $query->whereNull('document_id') : $query->where('document_id', $context->documentId);
            })
            ->where(function ($query) use ($context): void {
                $context->unitId === null ? $query->whereNull('unit_id') : $query->where('unit_id', $context->unitId);
            });
    }

    /** @return array<string, mixed> */
    private function identityAttributes(FailureData $failure, string $id, DateTimeImmutable $createdAt): array
    {
        return [
            'id' => $id, 'fingerprint' => $failure->fingerprint,
            'organization_id' => $failure->context->organizationId, 'project_id' => $failure->context->projectId,
            'session_id' => $failure->context->sessionId, 'document_id' => $failure->context->documentId,
            'page_id' => $failure->context->pageId, 'unit_id' => $failure->context->unitId,
            'checkpoint_id' => $failure->context->checkpointId, 'usage_attempt_id' => $failure->context->usageAttemptId,
            'stage' => $failure->context->stage->value, 'operation' => $failure->context->operation,
            'provider' => $failure->context->provider, 'model' => $failure->context->model,
            'category' => $failure->category->value, 'code' => $failure->code, 'created_at' => $createdAt,
        ];
    }

    /** @param array<string, mixed> $expected */
    private function sameIdentity(object $actual, array $expected): bool
    {
        foreach (['id', 'fingerprint', 'organization_id', 'project_id', 'session_id', 'document_id', 'page_id', 'unit_id',
            'checkpoint_id', 'usage_attempt_id', 'stage', 'operation', 'provider', 'model', 'category', 'code'] as $key) {
            if ((string) ($actual->{$key} ?? '') !== (string) ($expected[$key] ?? '')) {
                return false;
            }
        }

        return true;
    }

    private static function deterministicId(string $seed): string
    {
        $hex = substr(hash('sha256', $seed), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
