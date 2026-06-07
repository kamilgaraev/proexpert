<?php

declare(strict_types=1);

namespace Tests\Unit\OneCExchange;

use App\Models\OneCExchangeOperation;
use App\Services\OneCExchange\OneCExchangeIncidentRuleResolver;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class OneCExchangeIncidentRuleResolverTest extends TestCase
{
    public function test_dead_letter_incident_has_owner_deadline_actions_and_no_sensitive_text(): void
    {
        $operation = new OneCExchangeOperation();
        $operation->forceFill([
            'id' => 77,
            'operation_key' => 'operation-77',
            'correlation_id' => 'correlation-77',
            'direction' => 'export',
            'scope' => 'payment_documents',
            'status' => 'dead_letter',
            'failure_type' => 'server_error',
            'safe_error_code' => 'server_error',
            'safe_error_message' => 'SQLSTATE stack trace token secret constraint exception',
            'retry_count' => 5,
            'max_attempts' => 5,
            'retryable' => false,
            'updated_at' => CarbonImmutable::parse('2026-06-07 11:30:00'),
        ]);

        $incident = app(OneCExchangeIncidentRuleResolver::class)->resolveOperation(
            $operation,
            CarbonImmutable::parse('2026-06-07 12:00:00'),
            'dead_letter'
        );

        self::assertNotNull($incident);
        self::assertSame('dead_letter', $incident['scenario']);
        self::assertSame('critical', $incident['severity']);
        self::assertSame('manual_review_owner', $incident['owner']['key']);
        self::assertSame('2026-06-07T12:00:00.000000Z', $incident['response_deadline_at']);
        self::assertContains('open_operation', array_column($incident['actions'], 'type'));
        self::assertContains('send_to_1c_specialist', array_column($incident['actions'], 'type'));
        self::assertContains('retry', array_column($incident['actions'], 'type'));
        self::assertContains('move_to_dead_letter', array_column($incident['actions'], 'type'));

        $encoded = json_encode($incident, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('SQLSTATE', $encoded);
        self::assertStringNotContainsString('stack trace', $encoded);
        self::assertStringNotContainsString('token', strtolower($encoded));
        self::assertStringNotContainsString('secret', strtolower($encoded));
        self::assertStringNotContainsString('constraint', strtolower($encoded));
        self::assertStringNotContainsString('exception', strtolower($encoded));
    }

    public function test_requires_mapping_and_overdue_retry_resolve_to_specific_actions(): void
    {
        $mappingOperation = new OneCExchangeOperation();
        $mappingOperation->forceFill([
            'id' => 88,
            'operation_key' => 'operation-88',
            'correlation_id' => 'correlation-88',
            'direction' => 'import',
            'scope' => 'materials',
            'status' => 'requires_mapping',
            'failure_type' => 'mapping_missing',
            'safe_error_code' => 'mapping_missing',
            'safe_error_message' => 'Не найдено сопоставление для учетной системы.',
            'retry_count' => 0,
            'max_attempts' => 5,
            'retryable' => false,
            'updated_at' => CarbonImmutable::parse('2026-06-07 11:45:00'),
        ]);

        $retryOperation = new OneCExchangeOperation();
        $retryOperation->forceFill([
            'id' => 89,
            'operation_key' => 'operation-89',
            'correlation_id' => 'correlation-89',
            'direction' => 'export',
            'scope' => 'contracts',
            'status' => 'retry_scheduled',
            'failure_type' => 'timeout',
            'safe_error_code' => 'timeout',
            'safe_error_message' => 'Учетная система не ответила за отведенное время.',
            'retry_count' => 3,
            'max_attempts' => 5,
            'retryable' => true,
            'next_retry_at' => CarbonImmutable::parse('2026-06-07 11:20:00'),
            'updated_at' => CarbonImmutable::parse('2026-06-07 11:20:00'),
        ]);

        $resolver = app(OneCExchangeIncidentRuleResolver::class);
        $now = CarbonImmutable::parse('2026-06-07 12:00:00');

        $mappingIncident = $resolver->resolveOperation($mappingOperation, $now, 'requires_mapping');
        $retryIncident = $resolver->resolveOperation($retryOperation, $now, 'overdue_retry');

        self::assertSame('requires_mapping', $mappingIncident['scenario']);
        self::assertSame('mapping_owner', $mappingIncident['owner']['key']);
        self::assertContains('check_mapping', array_column($mappingIncident['actions'], 'type'));
        self::assertSame('overdue_retry', $retryIncident['scenario']);
        self::assertSame('integration_owner', $retryIncident['owner']['key']);
        self::assertTrue($retryIncident['actions'][array_search('retry', array_column($retryIncident['actions'], 'type'), true)]['enabled']);
    }

    public function test_system_incident_is_returned_when_delivery_is_unconfigured(): void
    {
        $incident = app(OneCExchangeIncidentRuleResolver::class)->resolveSystem(
            configured: false,
            deliveryEnabled: false,
            now: CarbonImmutable::parse('2026-06-07 12:00:00')
        );

        self::assertNotNull($incident);
        self::assertSame('delivery_unconfigured', $incident['scenario']);
        self::assertSame('critical', $incident['severity']);
        self::assertSame('integration_owner', $incident['owner']['key']);
        self::assertNull($incident['operation']);
        self::assertSame(['send_to_1c_specialist'], array_column($incident['actions'], 'type'));
    }
}
