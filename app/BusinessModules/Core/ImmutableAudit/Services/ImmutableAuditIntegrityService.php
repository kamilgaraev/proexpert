<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\ImmutableAudit\Services;

use App\BusinessModules\Core\ImmutableAudit\Models\ImmutableAuditEvent;
use DateTimeInterface;
use Illuminate\Support\Carbon;

final class ImmutableAuditIntegrityService
{
    public const HASH_FIELDS = [
        'sequence_id',
        'organization_id',
        'project_id',
        'domain',
        'event_type',
        'action',
        'result',
        'severity',
        'occurred_at',
        'recorded_at',
        'actor_type',
        'actor_user_id',
        'actor_snapshot',
        'impersonator_user_id',
        'source',
        'source_route',
        'source_model',
        'source_table',
        'source_event_id',
        'correlation_id',
        'idempotency_key',
        'subject_type',
        'subject_id',
        'subject_label',
        'related_subjects',
        'reason',
        'before_state',
        'after_state',
        'diff',
        'domain_context',
        'sensitive_fields',
        'redaction_policy_version',
        'chain_scope',
        'chain_version',
        'sealed_at',
        'seal_id',
        'integrity_status',
        'retention_until',
        'created_at',
    ];

    public function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    public function payloadHash(array $attributes): string
    {
        return hash('sha256', $this->canonicalJson($this->eventPayloadForHash($attributes)));
    }

    public function recordHash(array $attributes, string $payloadHash, ?string $previousHash): string
    {
        return hash('sha256', $this->canonicalJson([
            'sequence_id' => $attributes['sequence_id'] ?? null,
            'chain_scope' => $attributes['chain_scope'] ?? null,
            'chain_version' => $attributes['chain_version'] ?? null,
            'payload_hash' => $payloadHash,
            'previous_hash' => $previousHash,
        ]));
    }

    public function verifyEvent(ImmutableAuditEvent $event): array
    {
        $attributes = $this->attributesFromEvent($event);
        $payloadHash = $this->payloadHash($attributes);
        $recordHash = $this->recordHash($attributes, $payloadHash, $event->previous_hash);

        return [
            'event_id' => $event->id,
            'sequence_id' => $event->sequence_id,
            'payload_valid' => hash_equals((string) $event->payload_hash, $payloadHash),
            'record_valid' => hash_equals((string) $event->record_hash, $recordHash),
            'expected_payload_hash' => $payloadHash,
            'actual_payload_hash' => $event->payload_hash,
            'expected_record_hash' => $recordHash,
            'actual_record_hash' => $event->record_hash,
        ];
    }

    public function verifyChain(iterable $events): array
    {
        $checked = 0;
        $broken = [];
        $previousHash = null;

        foreach ($events as $event) {
            $checked++;
            $eventResult = $this->verifyEvent($event);
            $linkValid = $event->previous_hash === $previousHash;

            if (! $eventResult['payload_valid'] || ! $eventResult['record_valid'] || ! $linkValid) {
                $broken[] = [
                    'event_id' => $event->id,
                    'sequence_id' => $event->sequence_id,
                    'payload_valid' => $eventResult['payload_valid'],
                    'record_valid' => $eventResult['record_valid'],
                    'link_valid' => $linkValid,
                    'expected_previous_hash' => $previousHash,
                    'actual_previous_hash' => $event->previous_hash,
                ];
            }

            $previousHash = $event->record_hash;
        }

        return [
            'checked_events' => $checked,
            'broken_events' => count($broken),
            'valid' => $broken === [],
            'issues' => $broken,
        ];
    }

    public function eventPayloadForHash(array $attributes): array
    {
        $payload = [];

        foreach (self::HASH_FIELDS as $field) {
            $payload[$field] = $this->normalizeValue($attributes[$field] ?? null);
        }

        return $payload;
    }

    public function attributesFromEvent(ImmutableAuditEvent $event): array
    {
        $attributes = [];

        foreach (self::HASH_FIELDS as $field) {
            $attributes[$field] = $event->getAttribute($field);
        }

        return $attributes;
    }

    private function canonicalize(mixed $value): mixed
    {
        $value = $this->normalizeValue($value);

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof Carbon) {
            return $value->copy()->utc()->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->utc()->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        if (is_array($value)) {
            return $value;
        }

        return $value;
    }
}
