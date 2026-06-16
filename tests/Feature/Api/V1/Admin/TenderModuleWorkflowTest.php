<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class TenderModuleWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_returns_paginated_admin_response_with_summary_and_tenant_scope(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $this->allowModuleAccess();
        $this->allowAdminAccess();

        $sourceId = $this->createTenderSource($context->organization->id, 'manual', 'Ручной ввод');
        $ownTenderId = $this->createTender($context->organization->id, $sourceId, [
            'number' => 'TD-2026-0001',
            'title' => 'Строительство производственного корпуса',
            'status' => 'preparation',
            'priority' => 'high',
            'risk_level' => 'medium',
            'customer_name' => 'ООО Заказчик',
            'initial_max_price' => 1500000,
            'expected_bid_amount' => 1400000,
            'submission_deadline_at' => now()->addDays(3),
            'next_deadline_at' => now()->addDays(3),
        ]);
        $this->createTenderDeadline($ownTenderId, 'submission', now()->addDays(3));

        $foreignSourceId = $this->createTenderSource($foreignContext->organization->id, 'manual', 'Чужая площадка');
        $this->createTender($foreignContext->organization->id, $foreignSourceId, [
            'number' => 'TD-FOREIGN',
            'title' => 'Чужой тендер',
            'customer_name' => 'Чужая организация',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/tenders?per_page=20');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('meta.current_page', 1);
        $response->assertJsonPath('meta.per_page', 20);
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('summary.total', 1);
        $response->assertJsonPath('summary.active', 1);
        $response->assertJsonPath('summary.preparation', 1);
        $response->assertJsonPath('summary.amount_visible', true);
        $response->assertJsonPath('data.0.id', $ownTenderId);
        $response->assertJsonPath('data.0.number', 'TD-2026-0001');
        $response->assertJsonPath('data.0.customer.name', 'ООО Заказчик');
        $response->assertJsonPath('data.0.status_label', 'Подготовка');
        $response->assertJsonPath('data.0.risk_label', 'Средний');
        $response->assertJsonPath('data.0.initial_max_price', '1500000.00');
        $response->assertJsonPath('data.0.workflow_summary.available_actions.0', 'update');

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains('TD-FOREIGN', $ids);
    }

    public function test_create_validates_customer_and_source_requirements(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowModuleAccess();
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/tenders', [
                'title' => 'Тендер без заказчика и источника',
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors([
            'customer_name',
            'source_id',
        ]);
    }

    public function test_create_rejects_direct_workflow_fields(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowModuleAccess();
        $this->allowAdminAccess();

        $sourceId = $this->createTenderSource($context->organization->id, 'manual', 'Ручной ввод');

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/tenders', [
                'title' => 'Тендер с прямым статусом',
                'customer_name' => 'ООО Заказчик',
                'source_id' => $sourceId,
                'status' => 'submitted',
                'submitted_at' => now()->toJSON(),
                'winner_name' => 'ООО Победитель',
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors([
            'status',
            'submitted_at',
            'winner_name',
        ]);
    }

    public function test_detail_returns_nullable_links_and_hides_amounts_without_permission(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowModuleAccess();
        $this->allowAdminAccessWithoutAmountPermission();

        $sourceId = $this->createTenderSource($context->organization->id, 'zakupki_gov', 'ЕИС');
        $commercialProposalId = (string) Str::uuid();
        $tenderId = $this->createTender($context->organization->id, $sourceId, [
            'number' => 'TD-2026-0002',
            'title' => 'Ремонт фасада',
            'customer_name' => 'АО Городской заказчик',
            'status' => 'analysis',
            'commercial_proposal_id' => $commercialProposalId,
            'initial_max_price' => 2500000,
            'expected_bid_amount' => 2300000,
            'final_bid_amount' => 2200000,
            'winner_amount' => 2100000,
            'submission_deadline_at' => now()->addDays(5),
            'next_deadline_at' => now()->addDays(5),
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/tenders/{$tenderId}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $tenderId);
        $response->assertJsonPath('data.amount_visible', false);
        $response->assertJsonPath('data.initial_max_price', null);
        $response->assertJsonPath('data.expected_bid_amount', null);
        $response->assertJsonPath('data.final_bid_amount', null);
        $response->assertJsonPath('data.winner_amount', null);
        $response->assertJsonPath('data.links.crm_deal', null);
        $response->assertJsonPath('data.links.commercial_proposal.id', $commercialProposalId);
        $response->assertJsonPath('data.links.commercial_proposal.title', 'КП ' . $commercialProposalId);
        $response->assertJsonPath('data.links.project', null);
        $response->assertJsonPath('data.links.contract', null);
        $response->assertJsonPath('data.requirements', []);
        $response->assertJsonPath('data.files', []);
        $response->assertJsonPath('data.risks', []);
        $response->assertJsonPath('data.competitors', []);
        $response->assertJsonPath('data.timeline', []);
    }

    public function test_workflow_submit_returns_conflict_with_business_blockers(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowModuleAccess();
        $this->allowAdminAccess();

        $sourceId = $this->createTenderSource($context->organization->id, 'manual', 'Ручной ввод');
        $tenderId = $this->createTender($context->organization->id, $sourceId, [
            'number' => 'TD-2026-0003',
            'title' => 'Монтаж инженерных сетей',
            'customer_name' => 'ООО Инвестор',
            'status' => 'preparation',
            'submission_deadline_at' => null,
            'next_deadline_at' => null,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/tenders/{$tenderId}/workflow/submit", []);

        $response->assertConflict();
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Нельзя выполнить переход. Заполните обязательные данные.');
        $response->assertJsonPath('blockers.0.key', 'missing_submission_deadline');
        $response->assertJsonPath('blockers.0.label', 'Укажите срок подачи');
    }

    public function test_workflow_result_rejects_invalid_source_status(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowModuleAccess();
        $this->allowAdminAccess();

        $sourceId = $this->createTenderSource($context->organization->id, 'manual', 'Ручной ввод');
        $tenderId = $this->createTender($context->organization->id, $sourceId, [
            'number' => 'TD-2026-0004',
            'title' => 'Тендер без подачи',
            'customer_name' => 'ООО Заказчик',
            'status' => 'incoming',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/tenders/{$tenderId}/workflow/result", [
                'result' => 'won',
            ]);

        $response->assertConflict();
        $response->assertJsonPath('blockers.0.key', 'invalid_status');
    }

    private function createTenderSource(int $organizationId, string $code, string $label): string
    {
        $id = (string) Str::uuid();

        DB::table('tender_sources')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'code' => $code,
            'label' => $label,
            'source_type' => 'manual',
            'base_url' => null,
            'settings' => '{}',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createTender(int $organizationId, string $sourceId, array $overrides = []): string
    {
        $id = (string) Str::uuid();
        $now = now();

        DB::table('tenders')->insert(array_merge([
            'id' => $id,
            'organization_id' => $organizationId,
            'source_id' => $sourceId,
            'customer_company_id' => null,
            'customer_contact_id' => null,
            'owner_user_id' => null,
            'crm_deal_id' => null,
            'commercial_proposal_id' => null,
            'project_id' => null,
            'contract_id' => null,
            'number' => 'TD-' . Str::upper(Str::random(8)),
            'external_number' => null,
            'external_url' => null,
            'title' => 'Тендер',
            'description' => null,
            'customer_name' => 'Тестовый заказчик',
            'customer_inn' => null,
            'customer_kpp' => null,
            'customer_ogrn' => null,
            'status' => 'incoming',
            'priority' => 'normal',
            'risk_level' => 'medium',
            'initial_max_price' => null,
            'budget_missing_reason' => null,
            'expected_bid_amount' => null,
            'final_bid_amount' => null,
            'final_bid_amount_missing_reason' => null,
            'winner_amount' => null,
            'currency' => 'RUB',
            'published_at' => null,
            'questions_deadline_at' => null,
            'submission_deadline_at' => null,
            'submitted_at' => null,
            'submitted_by_user_id' => null,
            'submission_confirmation_file_id' => null,
            'submission_confirmation_url' => null,
            'opening_at' => null,
            'auction_at' => null,
            'result_expected_at' => null,
            'result_published_at' => null,
            'next_deadline_at' => null,
            'go_no_go_decision' => 'pending',
            'go_no_go_reason' => null,
            'decided_by_user_id' => null,
            'decided_at' => null,
            'lost_reason' => null,
            'cancel_reason' => null,
            'winner_name' => null,
            'requirements_summary' => null,
            'analysis_summary' => null,
            'requirements' => '{}',
            'evaluation_criteria' => '{}',
            'metadata' => '{}',
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ], $overrides));

        return $id;
    }

    private function createTenderDeadline(string $tenderId, string $kind, mixed $dueAt): void
    {
        DB::table('tender_deadlines')->insert([
            'id' => (string) Str::uuid(),
            'tender_id' => $tenderId,
            'kind' => $kind,
            'title' => 'Срок подачи',
            'due_at' => $dueAt,
            'completed_at' => null,
            'responsible_user_id' => null,
            'reminder_policy' => '{"days_before":[7,3,1],"same_day":true}',
            'is_required' => true,
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function allowModuleAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')
                ->andReturnUsing(static fn (int $organizationId, string $moduleSlug): bool => $moduleSlug === 'tenders');
        });
    }

    private function allowAdminAccess(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
        });
    }

    private function allowAdminAccessWithoutAmountPermission(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturnUsing(
                static fn (User $user, string $permission, array $context = []): bool => $permission !== 'tenders.amounts.view'
            );
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['admin_viewer']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
        });
    }
}
