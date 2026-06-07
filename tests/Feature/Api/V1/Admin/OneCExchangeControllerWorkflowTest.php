<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\OneCExchangeMapping;
use App\Models\OneCExchangeMessage;
use App\Models\OneCExchangeOperation;
use App\Models\OneCExchangeRun;
use App\Models\OneCExchangeToken;
use App\Models\OneCBase;
use App\Models\OneCIntegrationProfile;
use App\Models\OneCProfileSecret;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class OneCExchangeControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_exchange_workflow_is_scoped_and_does_not_expose_token_hashes(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $module = $this->createOneCModule();
        $this->activateModule($context->organization->id, $module->id);
        $this->activateModule($foreignContext->organization->id, $module->id);

        OneCExchangeToken::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'label' => 'Foreign exchange',
            'token_hash' => hash('sha256', 'foreign-token'),
        ]);
        OneCExchangeMapping::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'scope' => 'materials',
            'external_id' => 'foreign-material',
            'external_name' => 'Foreign material',
            'local_type' => 'materials',
            'local_id' => 999,
        ]);

        $statusResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/one-c-exchange/status');

        $statusResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.tokens_count', 0);

        $createTokenResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/one-c-exchange/tokens', [
                'label' => 'Main 1C exchange',
            ]);

        $createTokenResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token.label', 'Main 1C exchange');
        $plainToken = (string) $createTokenResponse->json('data.plain_token');
        $this->assertStringStartsWith('ph_1c_', $plainToken);
        $this->assertArrayNotHasKey('token_hash', $createTokenResponse->json('data.token'));

        $tokensResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/one-c-exchange/tokens');

        $tokensResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.label', 'Main 1C exchange');
        $this->assertArrayNotHasKey('token_hash', $tokensResponse->json('data.0'));

        $mappingResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/one-c-exchange/mappings', [
                'scope' => 'materials',
                'external_id' => '1c-material-42',
                'external_name' => 'Concrete M350',
                'local_type' => 'materials',
                'local_id' => 42,
                'payload' => ['unit' => 'm3'],
            ]);

        $mappingResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.external_id', '1c-material-42')
            ->assertJsonPath('data.payload.unit', 'm3');

        $mappingsResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/one-c-exchange/mappings?scope=materials');

        $mappingsResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.external_id', '1c-material-42');

        $importResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/one-c-exchange/import', [
                'scope' => 'materials',
                'items' => [
                    ['external_id' => '1c-material-42'],
                    ['external_id' => '1c-material-43'],
                ],
                'dry_run' => true,
            ]);

        $importResponse->assertOk()
            ->assertJsonPath('data.direction', 'import')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.total_count', 2)
            ->assertJsonPath('data.created_count', 2)
            ->assertJsonPath('data.summary.dry_run', true);

        $exportResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/one-c-exchange/export', [
                'scope' => 'materials',
                'filters' => ['changed_since' => '2026-05-01'],
            ]);

        $exportResponse->assertOk()
            ->assertJsonPath('data.direction', 'export')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.total_count', 0);

        $historyResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/one-c-exchange/history?per_page=1');

        $historyResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2);
        $this->assertSame(
            2,
            OneCExchangeRun::query()->where('organization_id', $context->organization->id)->count()
        );
        $this->assertSame(
            0,
            OneCExchangeRun::query()->where('organization_id', $foreignContext->organization->id)->count()
        );
    }

    public function test_admin_without_one_c_permissions_cannot_use_exchange_routes(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'admin_viewer');
        $module = $this->createOneCModule();
        $this->activateModule($context->organization->id, $module->id);

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/one-c-exchange/status')
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/one-c-exchange/tokens', [
                'label' => 'Forbidden token',
            ])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/one-c-exchange/profiles/1/test-connection')
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('one_c_exchange_tokens', [
            'organization_id' => $context->organization->id,
            'label' => 'Forbidden token',
        ]);
    }

    public function test_connection_profile_endpoint_returns_safe_smoke_result(): void
    {
        $context = AdminApiTestContext::create();
        $module = $this->createOneCModule();
        $this->activateModule($context->organization->id, $module->id);

        $base = OneCBase::query()->create([
            'organization_id' => $context->organization->id,
            'code' => 'main',
            'name' => 'Main 1C',
            'environment' => 'production',
            'connector' => 'http',
            'endpoint_url_encrypted' => 'https://one-c.example/exchange?api_key=hidden',
            'metadata_path' => '/metadata',
            'status' => 'active',
        ]);
        $profile = OneCIntegrationProfile::query()->create([
            'organization_id' => $context->organization->id,
            'one_c_base_id' => $base->id,
            'code' => 'main-profile',
            'name' => 'Main profile',
            'environment' => 'production',
            'auth_type' => 'bearer_token',
            'status' => 'active',
            'allowed_scopes' => ['materials'],
        ]);
        OneCProfileSecret::query()->create([
            'organization_id' => $context->organization->id,
            'one_c_integration_profile_id' => $profile->id,
            'type' => 'bearer_token',
            'label' => 'Main token',
            'secret_value_encrypted' => 'plain-secret-token',
            'fingerprint' => hash('sha256', 'plain-secret-token'),
            'status' => 'active',
        ]);

        Http::fake(['*' => Http::response([
            'protocol_version' => '1.0',
            'connector_version' => '2.4.1',
            'supported_scopes' => ['materials'],
        ])]);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/one-c-exchange/profiles/{$profile->id}/test-connection");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'ok')
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.profile.base.endpoint_display', 'https://one-c.example/exchange')
            ->assertJsonPath('data.profile.last_connection_check.result_code', 'ok')
            ->assertJsonMissing(['secret_value_encrypted' => 'plain-secret-token'])
            ->assertJsonMissing(['api_key' => 'hidden'])
            ->assertJsonMissing(['trace' => 'hidden']);

        $this->assertDatabaseHas('one_c_integration_profiles', [
            'id' => $profile->id,
            'connection_status' => 'ok',
            'last_connection_check_code' => 'ok',
        ]);
        $this->assertDatabaseHas('one_c_profile_audit_events', [
            'one_c_integration_profile_id' => $profile->id,
            'event_type' => 'connection_check_run',
            'result_code' => 'ok',
        ]);
    }

    public function test_monitoring_endpoints_return_health_summary_and_queue_metrics(): void
    {
        Carbon::setTestNow('2026-06-07 12:00:00');
        $this->beforeApplicationDestroyed(static fn () => Carbon::setTestNow());
        config(['one_c_exchange.delivery.enabled' => true]);

        $context = AdminApiTestContext::create();
        $module = $this->createOneCModule();
        $this->activateModule($context->organization->id, $module->id);
        config(['one_c_exchange.delivery.enabled' => true]);

        OneCExchangeToken::query()->create([
            'organization_id' => $context->organization->id,
            'label' => 'Main exchange',
            'token_hash' => hash('sha256', 'main-token'),
        ]);

        $pendingOperation = tap(OneCExchangeOperation::query()->create([
            'organization_id' => $context->organization->id,
            'operation_key' => 'operation-pending',
            'correlation_id' => 'correlation-pending',
            'direction' => 'export',
            'scope' => 'payment_documents',
            'status' => 'pending',
            'retry_count' => 0,
            'max_attempts' => 5,
            'retryable' => false,
        ]), function (OneCExchangeOperation $operation): void {
            $operation->forceFill([
                'created_at' => now()->subMinutes(45),
                'updated_at' => now()->subMinutes(45),
            ])->save();
        });
        $failedOperation = tap(OneCExchangeOperation::query()->create([
            'organization_id' => $context->organization->id,
            'operation_key' => 'operation-failed',
            'correlation_id' => 'correlation-failed',
            'direction' => 'export',
            'scope' => 'payment_documents',
            'status' => 'failed',
            'failure_type' => 'timeout',
            'safe_error_code' => 'timeout',
            'safe_error_message' => 'Учетная система не ответила за отведенное время.',
            'retry_count' => 1,
            'max_attempts' => 5,
            'retryable' => true,
            'next_retry_at' => now()->addMinutes(15),
        ]), function (OneCExchangeOperation $operation): void {
            $operation->forceFill([
                'created_at' => now()->subMinutes(30),
                'updated_at' => now()->subMinutes(30),
            ])->save();
        });
        tap(OneCExchangeOperation::query()->create([
            'organization_id' => $context->organization->id,
            'operation_key' => 'operation-dead-letter',
            'correlation_id' => 'correlation-dead-letter',
            'direction' => 'import',
            'scope' => 'materials',
            'status' => 'dead_letter',
            'retry_count' => 5,
            'max_attempts' => 5,
            'retryable' => false,
        ]), function (OneCExchangeOperation $operation): void {
            $operation->forceFill([
                'created_at' => now()->subMinutes(20),
                'updated_at' => now()->subMinutes(20),
            ])->save();
        });
        tap(OneCExchangeOperation::query()->create([
            'organization_id' => $context->organization->id,
            'operation_key' => 'operation-completed',
            'correlation_id' => 'correlation-completed',
            'direction' => 'export',
            'scope' => 'payment_documents',
            'status' => 'completed',
            'retry_count' => 0,
            'max_attempts' => 5,
            'retryable' => false,
            'started_at' => now()->subMinutes(15),
            'finished_at' => now()->subMinutes(14),
        ]), function (OneCExchangeOperation $operation): void {
            $operation->forceFill([
                'created_at' => now()->subMinutes(15),
                'updated_at' => now()->subMinutes(14),
            ])->save();
        });
        OneCExchangeMessage::query()->create([
            'organization_id' => $context->organization->id,
            'operation_id' => $failedOperation->id,
            'attempt_number' => 1,
            'status' => 'failed',
            'failure_type' => 'timeout',
            'retryable' => true,
            'duration_ms' => 1200,
            'sent_at' => now()->subMinutes(30),
            'received_at' => now()->subMinutes(30),
        ]);
        OneCExchangeMessage::query()->create([
            'organization_id' => $context->organization->id,
            'operation_id' => $pendingOperation->id,
            'attempt_number' => 1,
            'status' => 'pending',
            'retryable' => false,
            'duration_ms' => 300,
            'sent_at' => now()->subMinutes(45),
        ]);

        $healthResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/one-c-exchange/health');

        $healthResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.health', 'critical')
            ->assertJsonPath('data.summary.configured', true)
            ->assertJsonPath('data.summary.pending_count', 1)
            ->assertJsonPath('data.summary.failed_count', 1)
            ->assertJsonPath('data.summary.dead_letter_count', 1)
            ->assertJsonPath('data.summary.backlog_count', 1)
            ->assertJsonPath('data.summary.oldest_pending_age_minutes', 45)
            ->assertJsonPath('data.summary.window_total_count', 4)
            ->assertJsonPath('data.summary.window_success_count', 1)
            ->assertJsonPath('data.summary.window_failure_count', 2)
            ->assertJsonPath('data.summary.window_retry_count', 2)
            ->assertJsonPath('data.summary.avg_duration_ms', 750)
            ->assertJsonPath('data.generated_at', '2026-06-07T12:00:00.000000Z');

        $metricsResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/one-c-exchange/metrics');

        $metricsResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status_counts.pending', 1)
            ->assertJsonPath('data.status_counts.failed', 1)
            ->assertJsonPath('data.status_counts.dead_letter', 1)
            ->assertJsonPath('data.status_counts.completed', 1)
            ->assertJsonPath('data.direction_counts.export', 3)
            ->assertJsonPath('data.direction_counts.import', 1)
            ->assertJsonPath('data.window.total_count', 4)
            ->assertJsonPath('data.window.retry_count', 2);

        $monitoringResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/one-c-exchange/monitoring');

        $monitoringResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.notification_summary.total_count', 2)
            ->assertJsonPath('data.notification_summary.critical_count', 2)
            ->assertJsonPath('data.incidents.0.scenario', 'transport_unavailable')
            ->assertJsonPath('data.incidents.0.severity', 'critical')
            ->assertJsonPath('data.incidents.1.scenario', 'dead_letter')
            ->assertJsonPath('data.incidents.1.owner.key', 'manual_review_owner')
            ->assertJsonPath('data.incidents.1.operation.id', 3)
            ->assertJsonPath('data.incidents.1.actions.0.type', 'open_operation')
            ->assertJsonPath('data.problem_operations.0.incident.scenario', 'dead_letter')
            ->assertJsonPath('data.runbook.0.key', 'transport_unavailable')
            ->assertJsonPath('data.runbook.6.key', 'delivery_unconfigured');
    }

    public function test_manual_exchange_validation_errors_are_human_readable(): void
    {
        $context = AdminApiTestContext::create();
        $module = $this->createOneCModule();
        $this->activateModule($context->organization->id, $module->id);

        $tokenResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/one-c-exchange/tokens', []);

        $tokenResponse->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Заполните поле «название токена».')
            ->assertJsonPath('errors.label.0', 'Заполните поле «название токена».');

        $mappingResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/one-c-exchange/mappings', []);

        $mappingResponse->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Заполните поле «раздел обмена».')
            ->assertJsonPath('errors.scope.0', 'Заполните поле «раздел обмена».')
            ->assertJsonPath('errors.external_id.0', 'Заполните поле «внешний идентификатор».')
            ->assertJsonPath('errors.local_type.0', 'Заполните поле «тип локальной записи».')
            ->assertJsonPath('errors.local_id.0', 'Заполните поле «локальная запись».');

        $importResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/one-c-exchange/import', []);

        $importResponse->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Заполните поле «раздел обмена».')
            ->assertJsonPath('errors.scope.0', 'Заполните поле «раздел обмена».');

        $exportResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/one-c-exchange/export', []);

        $exportResponse->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Заполните поле «раздел обмена».')
            ->assertJsonPath('errors.scope.0', 'Заполните поле «раздел обмена».');
    }

    public function test_conflict_resolution_contract_returns_business_comparison_and_rejects_stale_decisions(): void
    {
        Carbon::setTestNow('2026-06-07 12:00:00');
        $this->beforeApplicationDestroyed(static fn () => Carbon::setTestNow());

        $context = AdminApiTestContext::create();
        $module = $this->createOneCModule();
        $this->activateModule($context->organization->id, $module->id);

        $operation = OneCExchangeOperation::query()->create([
            'organization_id' => $context->organization->id,
            'operation_key' => 'conflict-payment-100',
            'correlation_id' => 'correlation-conflict-payment-100',
            'direction' => 'export',
            'scope' => 'payment_documents',
            'entity_type' => 'payment_document',
            'entity_id' => '100',
            'external_id' => '1c-payment-100',
            'status' => 'rejected',
            'failure_type' => 'value_mismatch',
            'safe_error_code' => 'value_mismatch',
            'safe_error_message' => 'Сумма платежа отличается от значения в 1C.',
            'retry_count' => 1,
            'max_attempts' => 5,
            'retryable' => false,
            'source_hash' => 'source-hash-v1',
            'payload_hash' => 'payload-hash-v1',
            'safe_payload_preview' => [
                'number' => 'ПЛ-100',
                'token' => 'plain-secret-token',
                'stack_trace' => 'hidden stack',
            ],
            'summary' => [
                'field_differences' => [
                    [
                        'field' => 'amount',
                        'prohelper_value' => 125000,
                        'one_c_value' => 120000,
                    ],
                    [
                        'field' => 'contractor',
                        'prohelper_value' => 'ООО Строй',
                        'one_c_value' => 'ООО Строй',
                    ],
                ],
            ],
        ]);

        $listResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/one-c-exchange/conflicts?status=open&scope=payment_documents');

        $listResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.operation_key', 'conflict-payment-100')
            ->assertJsonPath('data.0.title', 'Значения ProHelper и 1C не совпадают')
            ->assertJsonPath('data.0.comparison_fields.0.field', 'amount')
            ->assertJsonPath('data.0.comparison_fields.0.prohelper_value', 125000)
            ->assertJsonPath('data.0.comparison_fields.0.one_c_value', 120000)
            ->assertJsonMissing(['token' => 'plain-secret-token'])
            ->assertJsonMissing(['stack_trace' => 'hidden stack']);

        $conflictId = (int) $listResponse->json('data.0.id');
        $version = (int) $listResponse->json('data.0.version');

        $detailResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/one-c-exchange/conflicts/{$conflictId}");

        $detailResponse->assertOk()
            ->assertJsonPath('data.id', $conflictId)
            ->assertJsonPath('data.history.0.action', 'created')
            ->assertJsonPath('data.available_actions.0.type', 'accept_prohelper');

        $resolveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/one-c-exchange/conflicts/{$conflictId}/actions", [
                'action' => 'accept_prohelper',
                'expected_version' => $version,
                'comment' => 'Оставляем оперативные данные ProHelper, 1C обновит сумму после повторной доставки.',
            ]);

        $resolveResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.resolution.decision', 'prohelper')
            ->assertJsonPath('data.history.0.action', 'accept_prohelper')
            ->assertJsonPath('data.history.0.comment', 'Оставляем оперативные данные ProHelper, 1C обновит сумму после повторной доставки.');

        $this->assertDatabaseHas('one_c_exchange_operations', [
            'id' => $operation->id,
            'status' => 'queued',
            'retryable' => true,
        ]);

        $staleResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/one-c-exchange/conflicts/{$conflictId}/actions", [
                'action' => 'accept_one_c',
                'expected_version' => $version,
                'comment' => 'Повторное решение со старой версией не должно сохраниться.',
            ]);

        $staleResponse->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Конфликт изменился. Обновите карточку и проверьте актуальные данные.');
    }

    private function createOneCModule(): Module
    {
        return Module::query()->create([
            'name' => '1C Basic Exchange',
            'slug' => 'one-c-basic-exchange',
            'version' => '1.0.0',
            'type' => 'addon',
            'billing_model' => 'free',
            'category' => 'integrations',
            'is_active' => true,
            'is_system_module' => false,
            'can_deactivate' => true,
            'permissions' => [
                'one_c_exchange.view',
                'one_c_exchange.manage_tokens',
                'one_c_exchange.manage_mappings',
                'one_c_exchange.import',
                'one_c_exchange.export',
                'one_c_exchange.history.view',
                'one_c_exchange.retry',
                'one_c_exchange.dead_letter.manage',
                'one_c_exchange.conflicts.view',
                'one_c_exchange.conflicts.resolve',
                'one_c_exchange.profiles.test_connection',
            ],
        ]);
    }

    private function activateModule(int $organizationId, int $moduleId): void
    {
        OrganizationModuleActivation::query()->create([
            'organization_id' => $organizationId,
            'module_id' => $moduleId,
            'status' => 'active',
            'activated_at' => now(),
        ]);
    }
}
