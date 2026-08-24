<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use App\BusinessModules\Features\ChangeManagement\Services\ChangeManagementService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ChangeManagementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_customer_manage_rfi_change_impact_variation_and_claim_lifecycle(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contractor = Contractor::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Подрядчик допработ',
        ]);
        $contract = Contract::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => 'CHG-CONTRACT-001',
            'date' => '2026-08-01',
            'subject' => 'Работы по проекту',
            'total_amount' => 500000,
            'currency' => 'RUB',
            'status' => 'active',
        ]);
        $allocationId = DB::table('contract_project_allocations')->insertGetId([
            'contract_id' => $contract->id,
            'project_id' => $project->id,
            'allocation_type' => 'fixed',
            'allocated_amount' => 500000,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->allowAccess();

        $rfi = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/change-management/rfis', [
                'project_id' => $project->id,
                'subject' => 'Уточнить узел армирования',
                'question' => 'Нужен ли дополнительный выпуск арматуры?',
                'addressee_type' => 'designer',
                'response_due_date' => now()->addDays(3)->toDateString(),
            ]);

        $rfi->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.workflow_summary.available_actions.0', 'send');
        $rfiId = (int) $rfi->json('data.id');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/change-management/rfis/{$rfiId}/send")
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/change-management/rfis/{$rfiId}/answer", [
                'answer' => 'Выпуск нужен по оси Б.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'answered');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/change-management/rfis/{$rfiId}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $change = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/change-management/changes', [
                'project_id' => $project->id,
                'title' => 'Дополнительное армирование',
                'reason' => 'designer_instruction',
                'description' => 'Добавить выпуск арматуры по RFI.',
                'initiator_type' => 'contractor',
                'related_rfi_id' => $rfiId,
                'affected_schedule_task_ids' => [101],
                'affected_estimate_item_ids' => [201],
                'monetary_context' => [
                    'currency' => 'RUB',
                    'contract_project_allocation_id' => $allocationId,
                    'contingency_opening_amount' => '125000.00',
                    'contingency_allocation_amount' => '0.00',
                    'contingency_release_amount' => '0.00',
                ],
            ]);

        $change->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.workflow_summary.available_actions.0', 'submit');
        $changeId = (int) $change->json('data.id');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/change-management/changes/{$changeId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $impact = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/change-management/changes/{$changeId}/impact", [
                'cost_delta' => 125000,
                'schedule_delta_days' => 4,
                'requires_contract_change' => true,
                'requires_estimate_revision' => true,
                'requires_procurement_update' => false,
                'requires_customer_approval' => true,
                'affected_schedule_task_ids' => [101, 102],
                'affected_estimate_item_ids' => [201],
                'affected_contract_ids' => [301],
                'summary' => 'Нужны деньги, срок и согласование заказчика.',
            ]);

        $impact->assertOk()
            ->assertJsonPath('data.status', 'impact_assessment')
            ->assertJsonPath('data.impact.cost_delta', '125000.00')
            ->assertJsonPath('data.problem_flags.0.code', 'schedule_impact');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/change-management/changes/{$changeId}/internal-review")
            ->assertOk()
            ->assertJsonPath('data.status', 'internal_review');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/change-management/changes/{$changeId}/customer-review")
            ->assertOk()
            ->assertJsonPath('data.status', 'customer_review');

        $approvedChange = app(ChangeManagementService::class)->customerApprove(
            ChangeRequest::query()->findOrFail($changeId),
            $context->user->id,
            '125000.00',
            'Согласовано заказчиком.',
        );
        self::assertSame('approved', $approvedChange->status);
        self::assertSame('approved', $approvedChange->approvals->last()?->status);

        $closeWithoutImplementation = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/change-management/changes/{$changeId}/close");
        $closeWithoutImplementation->assertStatus(422);

        $variation = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/change-management/changes/{$changeId}/variation-orders", [
                'variation_number' => 'VO-001',
                'amount' => 125000,
                'schedule_delta_days' => 4,
                'description' => 'Зафиксировано допсоглашение по армированию.',
            ]);

        $variation->assertCreated()
            ->assertJsonPath('data.variation_number', 'VO-001');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/change-management/changes/{$changeId}/variation-orders", [
                'variation_number' => 'VO-001',
                'amount' => 125000,
                'schedule_delta_days' => 4,
                'description' => 'Зафиксировано допсоглашение по армированию.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.id', $variation->json('data.id'));

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/change-management/changes/{$changeId}/variation-orders", [
                'variation_number' => 'VO-002',
                'amount' => 0.01,
            ])
            ->assertStatus(422);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/change-management/changes/{$changeId}/implement", [
                'implementation_comment' => 'Работы включены в график.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'implemented');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/change-management/changes/{$changeId}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $claim = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/change-management/claims', [
                'project_id' => $project->id,
                'change_request_id' => $changeId,
                'claim_number' => 'CL-001',
                'title' => 'Компенсация простоя',
                'description' => 'Простой из-за ожидания решения.',
                'amount' => 45000,
                'evidence' => [
                    ['type' => 'journal', 'reference' => 'J-1'],
                ],
            ]);

        $claim->assertCreated()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.evidence.0.reference', 'J-1');
    }

    public function test_change_management_guards_scope_customer_approval_and_variation_rules(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $this->allowAccess();

        $foreign = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/change-management/changes', [
                'project_id' => $foreignProject->id,
                'title' => 'Foreign change',
                'reason' => 'field_condition',
                'description' => 'Must be rejected by organization scope.',
                'initiator_type' => 'contractor',
            ]);
        $foreign->assertStatus(422);

        $change = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/change-management/changes', [
                'project_id' => $project->id,
                'title' => 'Локальная доработка',
                'reason' => 'field_condition',
                'description' => 'Без согласования заказчика.',
                'initiator_type' => 'contractor',
            ]);
        $change->assertCreated();
        $changeId = (int) $change->json('data.id');

        $variationTooEarly = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/change-management/changes/{$changeId}/variation-orders", [
                'variation_number' => 'VO-EARLY',
                'amount' => 1,
            ]);
        $variationTooEarly->assertStatus(422);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/change-management/changes/{$changeId}/submit")
            ->assertOk();
        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/change-management/changes/{$changeId}/impact", [
                'cost_delta' => 0,
                'schedule_delta_days' => 0,
                'requires_customer_approval' => false,
            ])
            ->assertOk();
        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/change-management/changes/{$changeId}/internal-review")
            ->assertOk();

        $customerApproval = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/customer/change-management/changes/{$changeId}/approve", [
                'comment' => 'Trying to approve when customer approval is not required.',
            ]);
        $customerApproval->assertStatus(422);
    }

    private function allowAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturnUsing(
                static fn (int $organizationId, string $moduleSlug): bool => in_array($moduleSlug, [
                    'change-management',
                    'project-management',
                    'contract-management',
                    'budget-estimates',
                    'schedule-management',
                    'procurement',
                    'payments',
                ], true)
            );
        });

        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin', 'customer_owner']);
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
