<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\ImmutableAudit\Services;

use App\BusinessModules\Core\ImmutableAudit\DTO\ImmutableAuditEventData;
use App\BusinessModules\Core\ImmutableAudit\Models\ImmutableAuditEvent;
use DateTimeInterface;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ImmutableAuditRecorder
{
    public function __construct(
        private readonly ImmutableAuditRedactor $redactor,
        private readonly ImmutableAuditIntegrityService $integrity,
        private readonly ?ConnectionInterface $connection = null,
    ) {}

    public function record(ImmutableAuditEventData $data): ImmutableAuditEvent
    {
        if ($data->sourceEventId !== null) {
            $existing = $this->findExisting($data);

            if ($existing !== null) {
                $this->assertSameLogicalEvent($existing, $data);

                return $existing;
            }
        }

        try {
            return $this->database()->transaction(function () use ($data): ImmutableAuditEvent {
                if ($this->database()->getDriverName() === 'pgsql') {
                    if ($this->phaseACompatibilityMode()) {
                        $this->database()->statement('LOCK TABLE immutable_audit_events IN SHARE ROW EXCLUSIVE MODE');
                    } else {
                        $chainScope = $data->chainScope ?? 'organization:'.$data->organizationId;
                        $this->database()->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$chainScope]);
                    }
                }

                if ($data->sourceEventId !== null) {
                    $existing = $this->findExisting($data);

                    if ($existing !== null) {
                        $this->assertSameLogicalEvent($existing, $data);

                        return $existing;
                    }
                }

                $attributes = $this->buildAttributes($data);

                $event = new ImmutableAuditEvent;
                $event->setConnection($this->database()->getName());
                $event->fill($attributes)->save();

                return $event;
            }, 3);
        } catch (QueryException $e) {
            if ($data->sourceEventId !== null) {
                $existing = $this->findExisting($data);

                if ($existing !== null) {
                    $this->assertSameLogicalEvent($existing, $data);

                    return $existing;
                }
            }

            throw $e;
        }
    }

    private function buildAttributes(ImmutableAuditEventData $data): array
    {
        $now = now()->setMicrosecond(0);
        $occurredAt = ($data->occurredAt ?? $now->copy())->copy()->setMicrosecond(0);
        $chainScope = $data->chainScope ?? 'organization:'.$data->organizationId;
        $previousHash = $this->previousHash($chainScope);
        $sequenceId = $this->nextSequenceId();
        $redacted = $this->redactPayloads($data);

        $attributes = [
            'sequence_id' => $sequenceId,
            'organization_id' => $data->organizationId,
            'project_id' => $data->projectId,
            'domain' => $data->domain,
            'event_type' => $data->eventType,
            'action' => $data->action,
            'result' => $data->result,
            'severity' => $data->severity,
            'occurred_at' => $occurredAt,
            'recorded_at' => $now,
            'actor_type' => $data->actorType,
            'actor_user_id' => $data->actorUserId,
            'actor_snapshot' => $redacted['actor_snapshot'],
            'impersonator_user_id' => $data->impersonatorUserId,
            'source' => $data->source,
            'source_route' => $data->sourceRoute,
            'source_model' => $data->sourceModel,
            'source_table' => $data->sourceTable,
            'source_event_id' => $data->sourceEventId,
            'correlation_id' => $data->correlationId,
            'idempotency_key' => $data->idempotencyKey,
            'subject_type' => $data->subjectType,
            'subject_id' => $data->subjectId === null ? null : (string) $data->subjectId,
            'subject_label' => $data->subjectLabel,
            'related_subjects' => $redacted['related_subjects'],
            'reason' => $data->reason,
            'before_state' => $redacted['before_state'],
            'after_state' => $redacted['after_state'],
            'diff' => $redacted['diff'],
            'domain_context' => $redacted['domain_context'],
            'sensitive_fields' => $redacted['sensitive_fields'],
            'redaction_policy_version' => ImmutableAuditRedactor::POLICY_VERSION,
            'previous_hash' => $previousHash,
            'chain_scope' => $chainScope,
            'chain_version' => $data->chainVersion,
            'sealed_at' => null,
            'seal_id' => null,
            'integrity_status' => 'pending',
            'retention_until' => $this->retentionUntil($data->domain, $occurredAt),
            'created_at' => $now,
        ];

        $payloadHash = $this->integrity->payloadHash($attributes);
        $attributes['payload_hash'] = $payloadHash;
        $attributes['record_hash'] = $this->integrity->recordHash($attributes, $payloadHash, $previousHash);

        return $attributes;
    }

    private function redactPayloads(ImmutableAuditEventData $data): array
    {
        $sensitiveFields = [];
        $payloads = [
            'actor_snapshot' => $data->actorSnapshot,
            'related_subjects' => $data->relatedSubjects,
            'before_state' => $data->beforeState,
            'after_state' => $data->afterState,
            'diff' => $data->diff,
            'domain_context' => $data->domainContext,
        ];

        foreach ($payloads as $key => $payload) {
            $result = $this->redactor->redactWithPaths($payload, $key);
            $payloads[$key] = $this->normalizeJsonPayload($result['payload']);
            $sensitiveFields = array_merge($sensitiveFields, $result['sensitive_fields']);
        }

        $payloads['sensitive_fields'] = array_values(array_unique($sensitiveFields));

        return $payloads;
    }

    private function normalizeJsonPayload(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->utc()->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalizeJsonPayload($item);
        }

        if (! array_is_list($normalized)) {
            ksort($normalized, SORT_STRING);
        }

        return $normalized;
    }

    private function findExisting(ImmutableAuditEventData $data): ?ImmutableAuditEvent
    {
        return $this->query()
            ->forOrganization($data->organizationId)
            ->where('domain', $data->domain)
            ->where('subject_type', $data->subjectType)
            ->where('subject_id', $data->subjectId === null ? null : (string) $data->subjectId)
            ->where('source', $data->source)
            ->where('source_event_id', $data->sourceEventId)
            ->first();
    }

    private function nextSequenceId(): int
    {
        if ($this->database()->getDriverName() === 'pgsql') {
            $row = $this->database()->selectOne('SELECT immutable_audit_allocate_sequence() AS value');

            return (int) ($row->value ?? 0);
        }

        return ((int) $this->query()->max('sequence_id')) + 1;
    }

    private function phaseACompatibilityMode(): bool
    {
        $row = $this->database()->selectOne("SELECT CASE WHEN to_regclass('immutable_audit_rollout') IS NULL THEN NULL ELSE (SELECT phase FROM immutable_audit_rollout WHERE singleton = true) END AS phase");

        return ($row->phase ?? null) === 'phase_a';
    }

    private function previousHash(string $chainScope): ?string
    {
        return $this->query()
            ->where('chain_scope', $chainScope)
            ->orderByDesc('sequence_id')
            ->value('record_hash');
    }

    private function retentionUntil(string $domain, Carbon $occurredAt): Carbon
    {
        $years = in_array($domain, ['warehouse', 'crm', 'procurement'], true) ? 5 : 7;

        return $occurredAt->copy()->addYearsNoOverflow($years);
    }

    private function database(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }

    private function assertSameLogicalEvent(ImmutableAuditEvent $existing, ImmutableAuditEventData $data): void
    {
        $redacted = $this->redactPayloads($data);
        $expected = [
            'organization_id' => $data->organizationId,
            'project_id' => $data->projectId,
            'domain' => $data->domain,
            'event_type' => $data->eventType,
            'action' => $data->action,
            'result' => $data->result,
            'severity' => $data->severity,
            'actor_type' => $data->actorType,
            'actor_user_id' => $data->actorUserId,
            'actor_snapshot' => $redacted['actor_snapshot'],
            'impersonator_user_id' => $data->impersonatorUserId,
            'source' => $data->source,
            'source_route' => $data->sourceRoute,
            'source_model' => $data->sourceModel,
            'source_table' => $data->sourceTable,
            'correlation_id' => $data->correlationId,
            'idempotency_key' => $data->idempotencyKey,
            'subject_label' => $data->subjectLabel,
            'subject_type' => $data->subjectType,
            'subject_id' => $data->subjectId === null ? null : (string) $data->subjectId,
            'related_subjects' => $redacted['related_subjects'],
            'reason' => $data->reason,
            'before_state' => $redacted['before_state'],
            'after_state' => $redacted['after_state'],
            'diff' => $redacted['diff'],
            'domain_context' => $redacted['domain_context'],
            'sensitive_fields' => $redacted['sensitive_fields'],
            'redaction_policy_version' => ImmutableAuditRedactor::POLICY_VERSION,
            'chain_scope' => $data->chainScope ?? 'organization:'.$data->organizationId,
            'chain_version' => $data->chainVersion,
        ];
        if ($data->occurredAt !== null) {
            $expected['occurred_at'] = $data->occurredAt->copy()->setMicrosecond(0);
        }
        $actual = [];
        foreach (array_keys($expected) as $field) {
            $actual[$field] = $existing->getAttribute($field);
        }

        $expectedHash = hash('sha256', $this->integrity->canonicalJson($expected));
        $actualHash = hash('sha256', $this->integrity->canonicalJson($actual));
        if (! hash_equals($expectedHash, $actualHash)) {
            throw new DomainException('immutable_audit_idempotency_conflict');
        }
    }

    private function query(): \Illuminate\Database\Eloquent\Builder
    {
        $event = new ImmutableAuditEvent;
        $event->setConnection($this->database()->getName());

        return $event->newQuery();
    }
}
