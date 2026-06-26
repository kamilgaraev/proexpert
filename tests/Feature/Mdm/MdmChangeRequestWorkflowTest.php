<?php

declare(strict_types=1);

namespace Tests\Feature\Mdm;

use App\BusinessModules\Core\Mdm\Models\MdmRecord;
use App\BusinessModules\Core\ImmutableAudit\Models\ImmutableAuditEvent;
use App\BusinessModules\Core\Mdm\Services\MdmRecordService;
use App\Models\Contractor;
use App\Models\OneCExchangeConflict;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class MdmChangeRequestWorkflowTest extends TestCase
{
    public function test_duplicate_submit_returns_existing_active_request(): void
    {
        $context = AdminApiTestContext::create();
        $contractor = Contractor::create([
            'organization_id' => $context->organization->id,
            'name' => 'ООО Дубль',
            'inn' => '7701000101',
        ]);
        app(MdmRecordService::class)->syncModel($contractor, 'contractor');

        $payload = [
            'entity_type' => 'contractor',
            'entity_id' => $contractor->id,
            'action' => 'update',
            'idempotency_key' => 'mdm-test-duplicate-1',
            'proposed_values' => [
                'name' => 'ООО Дубль Обновленный',
            ],
        ];

        $first = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/mdm/change-requests', $payload);
        $second = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/mdm/change-requests', $payload);

        $first->assertCreated();
        $second->assertCreated();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, MdmRecord::query()->where('entity_type', 'contractor')->count());
    }

    public function test_locked_field_blocks_submit_without_silent_drop(): void
    {
        $context = AdminApiTestContext::create();
        $contractor = Contractor::create([
            'organization_id' => $context->organization->id,
            'name' => 'ООО Блок',
            'inn' => '7701000102',
        ]);
        app(MdmRecordService::class)->syncModel($contractor, 'contractor');

        $create = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/mdm/change-requests', [
                'entity_type' => 'contractor',
                'entity_id' => $contractor->id,
                'action' => 'update',
                'proposed_values' => [
                    'organization_id' => $context->organization->id + 1,
                ],
            ]);

        $create->assertCreated();
        $create->assertJsonPath('data.validation_snapshot.has_blockers', true);
        $create->assertJsonPath('data.validation_snapshot.blockers.0.code', 'locked_field');

        $submit = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/mdm/change-requests/'.$create->json('data.id').'/submit');

        $submit->assertUnprocessable();
        $submit->assertJsonPath('success', false);
    }

    public function test_one_c_conflict_blocks_critical_change(): void
    {
        $context = AdminApiTestContext::create();
        $contractor = Contractor::create([
            'organization_id' => $context->organization->id,
            'name' => 'ООО 1С',
            'inn' => '7701000103',
        ]);
        app(MdmRecordService::class)->syncModel($contractor, 'contractor');

        OneCExchangeConflict::query()->create([
            'organization_id' => $context->organization->id,
            'conflict_key' => 'mdm-conflict-'.$contractor->id,
            'conflict_type' => 'mapping',
            'status' => 'open',
            'severity' => 'high',
            'scope' => 'counterparties',
            'entity_type' => 'contractor',
            'entity_id' => $contractor->id,
            'title' => 'Конфликт реквизитов',
            'detected_at' => now(),
        ]);

        $create = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/mdm/change-requests', [
                'entity_type' => 'contractor',
                'entity_id' => $contractor->id,
                'action' => 'update',
                'proposed_values' => [
                    'name' => 'ООО 1С Новое',
                ],
            ]);

        $create->assertCreated();
        $create->assertJsonPath('data.validation_snapshot.has_blockers', true);
        $create->assertJsonPath('data.validation_snapshot.blockers.0.code', 'one_c_conflict');
    }

    public function test_stale_payload_is_rejected_on_apply(): void
    {
        $context = AdminApiTestContext::create();
        $contractor = Contractor::create([
            'organization_id' => $context->organization->id,
            'name' => 'ООО Версия',
            'inn' => '7701000104',
        ]);
        $record = app(MdmRecordService::class)->syncModel($contractor, 'contractor');

        $create = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/mdm/change-requests', [
                'entity_type' => 'contractor',
                'entity_id' => $contractor->id,
                'action' => 'update',
                'proposed_values' => [
                    'contact_person' => 'Иван Петров',
                ],
            ]);
        $changeRequestId = $create->json('data.id');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/mdm/change-requests/{$changeRequestId}/submit")
            ->assertOk();
        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/mdm/change-requests/{$changeRequestId}/approve")
            ->assertOk();

        $record->update(['version' => ((int) $record->version) + 1]);

        $apply = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/mdm/change-requests/{$changeRequestId}/apply");

        $apply->assertUnprocessable();
        $apply->assertJsonPath('success', false);
        $this->assertDatabaseHas('contractors', [
            'id' => $contractor->id,
            'contact_person' => null,
        ]);
    }

    public function test_critical_change_request_cannot_be_applied_by_creator(): void
    {
        $context = AdminApiTestContext::create();
        $contractor = Contractor::create([
            'organization_id' => $context->organization->id,
            'name' => 'ООО Контроль',
            'inn' => '7701000199',
        ]);
        app(MdmRecordService::class)->syncModel($contractor, 'contractor');

        $create = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/mdm/change-requests', [
                'entity_type' => 'contractor',
                'entity_id' => $contractor->id,
                'action' => 'update',
                'proposed_values' => [
                    'name' => 'ООО Контроль Новое',
                ],
            ]);

        $create->assertCreated();
        $changeRequestId = $create->json('data.id');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/mdm/change-requests/{$changeRequestId}/submit")
            ->assertOk();
        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/mdm/change-requests/{$changeRequestId}/approve")
            ->assertOk();

        $apply = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/mdm/change-requests/{$changeRequestId}/apply");

        $apply->assertUnprocessable();
        $apply->assertJsonPath('success', false);
        $this->assertDatabaseHas('immutable_audit_events', [
            'organization_id' => $context->organization->id,
            'domain' => 'sod',
            'source' => 'erp_controls',
            'event_type' => 'erp_control.decision.blocked',
            'action' => 'mdm.change_requests.apply',
            'result' => 'blocked',
            'subject_type' => 'mdm_change_request',
            'subject_id' => (string) $changeRequestId,
        ]);

        $event = ImmutableAuditEvent::query()
            ->where('organization_id', $context->organization->id)
            ->where('domain', 'sod')
            ->where('source', 'erp_controls')
            ->firstOrFail();

        $this->assertSame('same_actor_mdm_create_apply', $event->domain_context['blockers'][0]['code']);
    }
}
