<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Core\ImmutableAudit\Models\ImmutableAuditEvent;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditIntegrityService;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ErpControlsControllerTest extends TestCase
{
    private int $sequence = 1;

    public function test_policies_returns_filtered_control_matrix(): void
    {
        $context = AdminApiTestContext::create();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/erp-controls/policies?domain=mdm');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.0.domain', 'mdm');
        $response->assertJsonPath('summary.total', 1);
        $response->assertJsonPath('summary.critical', 1);
    }

    public function test_conflicts_registry_is_org_scoped_and_resolution_closes_conflict(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $conflict = $this->createErpControlEvent($context->organization, [
            'action' => 'mdm.change_requests.apply',
            'result' => 'blocked',
            'severity' => 'critical',
            'event_type' => 'erp_control.decision.blocked',
            'subject_type' => 'mdm_change_request',
            'subject_id' => '10',
            'subject_label' => 'mdm.change_requests.apply',
            'domain_context' => [
                'domain' => 'mdm',
                'message' => 'Нельзя применить критичное изменение мастер-данных, созданное этим же пользователем.',
                'blockers' => [
                    ['code' => 'same_actor_mdm_create_apply', 'severity' => 'blocking'],
                ],
                'warnings' => [],
                'required_actions' => ['request_independent_approval'],
                'override_available' => false,
            ],
        ]);
        $this->createErpControlEvent(Organization::factory()->verified()->create(), [
            'event_type' => 'erp_control.decision.blocked',
            'result' => 'blocked',
            'domain_context' => ['domain' => 'mdm'],
        ]);

        $index = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/erp-controls/conflicts?domain=mdm');

        $index->assertOk();
        $index->assertJsonPath('meta.total', 1);
        $index->assertJsonPath('data.0.id', $conflict->id);

        $resolve = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/erp-controls/conflicts/{$conflict->id}/resolve", [
                'decision' => 'request_review',
                'reason' => 'Передано на независимую проверку финансовому контролеру.',
            ]);

        $resolve->assertOk();
        $resolve->assertJsonPath('data.decision', 'resolved');

        $afterResolve = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/erp-controls/conflicts?domain=mdm');

        $afterResolve->assertOk();
        $afterResolve->assertJsonPath('meta.total', 0);
    }

    private function createErpControlEvent(Organization $organization, array $attributes = []): ImmutableAuditEvent
    {
        $now = Carbon::parse('2026-06-22 10:00:00')->addSeconds($this->sequence);
        $base = array_merge([
            'sequence_id' => $this->sequence++,
            'organization_id' => $organization->id,
            'project_id' => null,
            'domain' => 'sod',
            'event_type' => 'erp_control.decision.allowed',
            'action' => 'mdm.change_requests.apply',
            'result' => 'allowed',
            'severity' => 'critical',
            'occurred_at' => $now,
            'recorded_at' => $now,
            'actor_type' => 'user',
            'actor_user_id' => null,
            'actor_snapshot' => [],
            'impersonator_user_id' => null,
            'source' => 'erp_controls',
            'source_route' => null,
            'source_model' => null,
            'source_table' => 'immutable_audit_events',
            'source_event_id' => null,
            'correlation_id' => null,
            'idempotency_key' => null,
            'subject_type' => 'mdm_change_request',
            'subject_id' => '1',
            'subject_label' => 'mdm.change_requests.apply',
            'related_subjects' => [],
            'reason' => null,
            'before_state' => [],
            'after_state' => [],
            'diff' => [],
            'domain_context' => [
                'domain' => 'mdm',
                'message' => 'Операция может быть выполнена.',
                'blockers' => [],
                'warnings' => [],
                'required_actions' => [],
                'override_available' => false,
            ],
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
