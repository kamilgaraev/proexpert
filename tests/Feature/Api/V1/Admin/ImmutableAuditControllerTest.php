<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Core\ImmutableAudit\Models\ImmutableAuditEvent;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditIntegrityService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ImmutableAuditControllerTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1;

    public function test_index_returns_events_for_current_organization_only(): void
    {
        $this->allowImmutableAuditPermissions();
        $context = AdminApiTestContext::create();

        $ownEvent = $this->createEvent($context->organization, [
            'domain' => 'payments',
            'event_type' => 'payments.approved',
            'subject_label' => 'PAY-1',
        ]);
        $this->createEvent(Organization::factory()->verified()->create(), [
            'domain' => 'payments',
            'subject_label' => 'FOREIGN',
        ]);
        $this->createEvent($context->organization, [
            'domain' => 'mdm',
            'subject_label' => 'MDM-1',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/immutable-audit/events?domain=payments&per_page=10');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.id', $ownEvent->id);
        $response->assertJsonPath('data.0.domain', 'payments');
    }

    public function test_show_returns_masked_detail_for_own_event(): void
    {
        $this->allowImmutableAuditPermissions();
        $context = AdminApiTestContext::create();
        $event = $this->createEvent($context->organization, [
            'before_state' => ['status' => 'draft', 'password' => '[скрыто]'],
            'after_state' => ['status' => 'approved'],
            'sensitive_fields' => ['before_state.password'],
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/immutable-audit/events/{$event->id}");

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.id', $event->id);
        $response->assertJsonPath('data.before_state.password', '[скрыто]');
        $response->assertJsonPath('data.redaction.sensitive_fields.0', 'before_state.password');
    }

    public function test_event_integrity_reports_valid_event(): void
    {
        $this->allowImmutableAuditPermissions();
        $context = AdminApiTestContext::create();
        $event = $this->createEvent($context->organization);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/immutable-audit/events/{$event->id}/integrity");

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.valid', true);
        $response->assertJsonPath('data.payload_valid', true);
        $response->assertJsonPath('data.record_valid', true);
    }

    public function test_export_returns_filtered_evidence_csv(): void
    {
        $this->allowImmutableAuditPermissions();
        $context = AdminApiTestContext::create();
        $this->createEvent($context->organization, [
            'domain' => 'payments',
            'event_type' => 'payments.approved',
            'subject_label' => 'PAY-1',
        ]);
        $this->createEvent($context->organization, [
            'domain' => 'mdm',
            'event_type' => 'mdm.change_request.applied',
            'subject_label' => 'MDM-1',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->get('/api/v1/admin/immutable-audit/events/export?domain=payments');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $response->assertSee('PAY-1', false);
        $response->assertDontSee('MDM-1', false);
    }

    private function allowImmutableAuditPermissions(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
        });
    }

    private function createEvent(Organization $organization, array $attributes = []): ImmutableAuditEvent
    {
        $now = Carbon::parse('2026-06-22 10:00:00')->addSeconds($this->sequence);
        $base = array_merge([
            'sequence_id' => $this->sequence++,
            'organization_id' => $organization->id,
            'project_id' => null,
            'domain' => 'payments',
            'event_type' => 'payments.created',
            'action' => 'created',
            'result' => 'success',
            'severity' => 'info',
            'occurred_at' => $now,
            'recorded_at' => $now,
            'actor_type' => 'system',
            'actor_user_id' => null,
            'actor_snapshot' => [],
            'impersonator_user_id' => null,
            'source' => 'feature_test',
            'source_route' => null,
            'source_model' => null,
            'source_table' => 'immutable_audit_events',
            'source_event_id' => 'test-'.$this->sequence,
            'correlation_id' => 'corr-test',
            'idempotency_key' => null,
            'subject_type' => 'payment',
            'subject_id' => '1',
            'subject_label' => 'PAY-1',
            'related_subjects' => [],
            'reason' => null,
            'before_state' => [],
            'after_state' => [],
            'diff' => [],
            'domain_context' => [],
            'sensitive_fields' => [],
            'redaction_policy_version' => '2026-06-22-v1',
            'previous_hash' => null,
            'chain_scope' => 'organization:'.$organization->id,
            'chain_version' => 1,
            'sealed_at' => null,
            'seal_id' => null,
            'integrity_status' => 'pending',
            'retention_until' => $now->copy()->addYearsNoOverflow(7),
            'created_at' => $now,
        ], $attributes);

        $integrity = new ImmutableAuditIntegrityService();
        $payloadHash = $integrity->payloadHash($base);
        $base['payload_hash'] = $payloadHash;
        $base['record_hash'] = $integrity->recordHash($base, $payloadHash, $base['previous_hash']);

        return ImmutableAuditEvent::query()->create($base);
    }
}
