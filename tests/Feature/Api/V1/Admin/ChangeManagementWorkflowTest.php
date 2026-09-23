<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\ChangeManagement\Http\Resources\ChangeRfiResource;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeManagementRfi;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use App\BusinessModules\Features\ChangeManagement\Services\ChangeManagementService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Auth\WebAuthTokenService;
use App\Services\Project\ProjectParticipantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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
        $recipientContext = AdminApiTestContext::create();
        DB::table('project_organization')->insert([
            'project_id' => $project->id,
            'organization_id' => $recipientContext->organization->id,
            'role' => 'contractor',
            'role_new' => 'contractor',
            'is_active' => true,
            'added_by_user_id' => $context->user->id,
            'invited_at' => now(),
            'accepted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(ProjectParticipantService::class)->saveHierarchy($project, (int) $context->organization->id, [[
            'organization_id' => (int) $recipientContext->organization->id,
            'parent_organization_id' => (int) $context->organization->id,
        ]], $context->user);
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
                'recipient_organization_id' => $recipientContext->organization->id,
                'response_due_date' => now()->addDays(3)->toDateString(),
            ]);

        $rfi->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.workflow_summary.available_actions.0', 'send');
        $rfiId = (int) $rfi->json('data.id');

        $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/change-management/rfis/{$rfiId}/attachments", [
                'file' => UploadedFile::fake()->create('payload.exe', 1, 'application/octet-stream'),
            ])
            ->assertStatus(422);

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/change-management/rfis?project_id='.$project->id)
            ->assertOk()
            ->assertJsonPath('data.0.history', []);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/change-management/rfis/{$rfiId}/send")
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');

        $authorRfis = app(ChangeManagementService::class)->paginateRfis(
            (int) $context->organization->id,
            20,
            ['project_id' => $project->id],
            true,
            (int) $context->user->id,
        );
        $this->assertContains($rfiId, array_map(static fn (ChangeManagementRfi $item): int => (int) $item->id, $authorRfis->items()));

        $recipientRfis = app(ChangeManagementService::class)->paginateRfis(
            (int) $recipientContext->organization->id,
            20,
            ['project_id' => $project->id, 'direction' => 'incoming'],
            true,
            (int) $recipientContext->user->id,
        );
        $this->assertContains($rfiId, array_map(static fn (ChangeManagementRfi $item): int => (int) $item->id, $recipientRfis->items()));

        $legacyRfi = ChangeManagementRfi::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $context->user->id,
            'rfi_number' => 'RFI-LEGACY',
            'subject' => 'Старый запрос без адресата',
            'question' => 'Вопрос из старой записи.',
            'addressee_type' => 'organization',
            'status' => 'sent',
            'attachments' => [],
            'metadata' => [],
        ]);
        $authorRfisAfterLegacy = app(ChangeManagementService::class)->paginateRfis(
            (int) $context->organization->id,
            20,
            ['project_id' => $project->id],
            true,
            (int) $context->user->id,
        );
        $this->assertNotContains(
            (int) $legacyRfi->id,
            array_map(static fn (ChangeManagementRfi $item): int => (int) $item->id, $authorRfisAfterLegacy->items()),
        );

        $this->withHeaders($recipientContext->authHeaders())
            ->postJson("/api/v1/admin/change-management/rfis/{$rfiId}/answer", [
                'answer' => 'Выпуск нужен по оси Б.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'answered');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/change-management/rfis/{$rfiId}/clarification", [
                'message' => 'Укажите точную отметку.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'clarification_requested');

        $this->withHeaders($recipientContext->authHeaders())
            ->postJson("/api/v1/admin/change-management/rfis/{$rfiId}/answer", [
                'answer' => 'Отметка +3.200.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'answered');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/change-management/rfis/{$rfiId}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/change-management/rfis/{$rfiId}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.history.6.event', 'closed');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/change-management/rfis/{$rfiId}/send")
            ->assertStatus(409);

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
        $contractor = Contractor::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Подрядчик локального изменения',
        ]);
        $contract = Contract::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => 'CHG-SCOPE-001',
            'date' => '2026-08-01',
            'subject' => 'Работы по проекту',
            'total_amount' => 1000,
            'currency' => 'RUB',
            'status' => 'active',
        ]);
        $allocationId = DB::table('contract_project_allocations')->insertGetId([
            'contract_id' => $contract->id,
            'project_id' => $project->id,
            'allocation_type' => 'fixed',
            'allocated_amount' => 1000,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
                'monetary_context' => [
                    'currency' => 'RUB',
                    'contract_project_allocation_id' => $allocationId,
                    'contingency_opening_amount' => '0.00',
                    'contingency_allocation_amount' => '0.00',
                    'contingency_release_amount' => '0.00',
                ],
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
                'requires_customer_approval' => true,
            ])
            ->assertOk();
        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/change-management/changes/{$changeId}/internal-review")
            ->assertOk();
        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/change-management/changes/{$changeId}/customer-review")
            ->assertOk();

        $customerSession = DB::table('user_auth_sessions')
            ->where('user_id', $context->user->id)
            ->where('organization_id', $context->organization->id)
            ->value('session_uuid');
        $customerToken = app(WebAuthTokenService::class)->issue(
            $context->user,
            'customer',
            (string) $customerSession,
            (int) $context->organization->id,
            false,
        );
        $this->withHeaders([
            'Authorization' => 'Bearer '.$customerToken->accessToken,
            'Accept' => 'application/json',
            'Origin' => 'https://customer.1мост.рф',
        ])->postJson("/api/v1/customer/change-management/changes/{$changeId}/approve", [
            'approved_cost_amount' => '0.00',
            'comment' => 'Согласовано.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $customerApproval = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/customer/change-management/changes/{$changeId}/approve", [
                'approved_cost_amount' => '0.00',
                'comment' => 'Запрос с токеном админки должен быть отклонён.',
            ]);
        $this->assertContains($customerApproval->getStatusCode(), [401, 403]);
    }

    public function test_change_management_rfi_routes_reject_tokens_from_the_other_interface(): void
    {
        $adminContext = AdminApiTestContext::create();
        $customerContext = AdminApiTestContext::create(roleSlug: 'customer_owner');
        $foreignContext = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $adminContext->organization->id]);
        $this->allowAccess();
        $customerSession = DB::table('user_auth_sessions')
            ->where('user_id', $customerContext->user->id)
            ->where('organization_id', $customerContext->organization->id)
            ->value('session_uuid');
        $customerToken = app(WebAuthTokenService::class)->issue(
            $customerContext->user,
            'customer',
            (string) $customerSession,
            (int) $customerContext->organization->id,
            false,
        );

        $adminOnCustomer = $this->withHeaders($adminContext->authHeaders())
            ->getJson('/api/v1/customer/change-management/rfis?project_id=1')
            ->getStatusCode();
        $this->assertContains($adminOnCustomer, [401, 403]);

        $customerOnAdmin = $this->withHeaders([
            'Authorization' => 'Bearer '.$customerToken->accessToken,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/admin/change-management/rfis')
            ->getStatusCode();
        $this->assertContains($customerOnAdmin, [401, 403]);

        $rfi = $this->withHeaders($adminContext->authHeaders())
            ->postJson('/api/v1/admin/change-management/rfis', [
                'project_id' => $project->id,
                'subject' => 'Изоляция RFI',
                'question' => 'Чужая организация не должна видеть вопрос.',
            ])
            ->assertCreated();
        $rfiId = (int) $rfi->json('data.id');

        $this->withHeaders($foreignContext->authHeaders())
            ->getJson("/api/v1/admin/change-management/rfis/{$rfiId}")
            ->assertStatus(404);

        $this->withHeaders($foreignContext->authHeaders())
            ->getJson("/api/v1/admin/change-management/rfis/{$rfiId}/attachments/00000000-0000-4000-8000-000000000000/download")
            ->assertStatus(404);

        $this->withHeaders($foreignContext->authHeaders())
            ->getJson('/api/v1/admin/change-management/rfis?project_id='.$project->id)
            ->assertStatus(403);
    }

    public function test_rfi_service_and_available_actions_enforce_actor_permissions(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'customer_viewer');
        $this->instance(AuthorizationService::class, \Mockery::mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('can')->andReturn(false);
        }));
        $rfi = new ChangeManagementRfi([
            'organization_id' => $context->organization->id,
            'recipient_organization_id' => 2,
            'project_id' => 1,
            'status' => 'draft',
            'attachments' => [],
            'metadata' => [],
        ]);

        $request = Request::create('/api/v1/admin/change-management/rfis/1', 'GET');
        $request->setUserResolver(static fn (): User => $context->user);
        $request->attributes->set('current_organization_id', $context->organization->id);
        $resource = (new ChangeRfiResource($rfi))->toArray($request);
        $this->assertSame([], $resource['available_actions']);

        $service = app(ChangeManagementService::class);
        foreach ([
            fn () => $service->sendRfi($rfi, (int) $context->organization->id, (int) $context->user->id, 2),
            fn () => $service->rfiAttachmentUrl($rfi, (int) $context->organization->id, (int) $context->user->id, 'attachment-id'),
        ] as $action) {
            try {
                $action();
                $this->fail('Direct RFI service invocation must enforce actor permissions.');
            } catch (\DomainException) {
                $this->assertTrue(true);
            }
        }
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
