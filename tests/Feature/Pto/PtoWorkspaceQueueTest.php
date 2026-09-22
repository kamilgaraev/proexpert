<?php

declare(strict_types=1);

namespace Tests\Feature\Pto;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentTransmittal;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentRequirementsService;
use App\BusinessModules\Features\ExecutiveDocumentation\Support\ExecutiveDocumentProfileRegistry;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\CompletedWork;
use App\Models\ConstructionJournal;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\PtoWorkspaceTask;
use App\Models\ScheduleTask;
use App\Models\User;
use App\Models\WorkType;
use App\Modules\Core\AccessController;
use App\Services\Pto\PtoWorkspaceQuery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class PtoWorkspaceQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-21 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_missing_signature_has_one_source_key_and_manual_complete_does_not_bypass(): void
    {
        [$context, $project, $set] = $this->assignedContext();
        $this->allowAccess();
        $requirement = $this->actRequirement($set, $context);
        $version = $this->approvedAct($set, $context, $project, ['contractor_control_representative']);
        $requirement->forceFill([
            'evidence' => [['version_id' => $version->id, 'coverage' => ['project_id' => (int) $project->id]]],
        ])->save();

        $queue = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/pto/work-queue?project_id='.$project->id);
        $queue->assertOk();
        $blockers = collect($queue->json('data'))->where('category', 'blocker')->values();
        self::assertCount(1, $blockers);
        $sourceKey = PtoWorkspaceQuery::requirementSourceKey((int) $requirement->id);
        self::assertSame($sourceKey, $blockers[0]['source_key']);
        self::assertFalse($blockers[0]['priority_basis']['overdue']);
        self::assertSame('Срок не назначен', $blockers[0]['due_label']);
        self::assertSame('collect_signatures', $blockers[0]['next_action']['key']);
        self::assertSame(1, (int) $queue->json('summary.blocker'));

        $completeness = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/pto/completeness?project_id='.$project->id);
        $completeness->assertOk();
        $rows = collect($completeness->json('data'))->where('source_key', $sourceKey)->values();
        self::assertCount(1, $rows);
        self::assertStringContainsString('подпис', mb_strtolower((string) $rows[0]['signatures_status']));

        $manual = $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/pto/tasks', [
            'source_key' => $sourceKey,
            'project_id' => $project->id,
            'title' => 'Собрать подпись',
            'responsible_user_id' => $context->user->id,
            'due_on' => '2026-09-22',
        ], $context->authHeaders());
        $manual->assertOk();
        self::assertSame(1, PtoWorkspaceTask::query()->where('source_key', $sourceKey)->count());
        $taskId = (int) $manual->json('data.id');
        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/pto/tasks/'.$taskId.'/complete')
            ->assertOk();
        self::assertSame(PtoWorkspaceTask::STATUS_COMPLETED, PtoWorkspaceTask::query()->find($taskId)?->status);

        $afterComplete = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/pto/work-queue?category=blocker&project_id='.$project->id);
        $afterComplete->assertOk();
        self::assertCount(1, $afterComplete->json('data'));
        self::assertSame($sourceKey, $afterComplete->json('data.0.source_key'));

        $document = ExecutiveDocument::query()->where('document_set_id', $set->id)->firstOrFail();
        $document->forceFill([
            'signatories' => $this->signatories([]),
        ])->save();
        $signed = $document->versions()->create([
            'organization_id' => $context->organization->id,
            'uploaded_by' => $context->user->id,
            'version_number' => '2.0',
            'file_url' => 's3://t23/act-signed.pdf',
            'status' => 'approved',
            'content_hash' => hash('sha256', 'signed'),
            'profile_snapshot' => $version->profile_snapshot,
            'basis_snapshot' => array_replace_recursive($version->basis_snapshot ?? [], [
                'document' => ['signatories' => $this->signatories([])],
            ]),
        ]);
        app(ExecutiveDocumentRequirementsService::class)->attachEvidence(
            $requirement->fresh(),
            $signed->id,
            ['project_id' => (int) $project->id],
            $context->user,
            app(AuthorizationService::class)
        );

        $afterSign = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/pto/work-queue?category=blocker&project_id='.$project->id);
        $afterSign->assertOk();
        self::assertSame([], $afterSign->json('data'));
        self::assertSame(0, (int) $afterSign->json('summary.blocker'));
    }

    public function test_upcoming_work_without_date_is_incomplete_not_risk_and_hidden_project_is_excluded(): void
    {
        [$context, $project, $set] = $this->assignedContext();
        $this->allowAccess();
        $this->actRequirement($set, $context);
        CompletedWork::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'quantity' => 8,
            'price' => 1500,
            'total_amount' => 12000,
            'completion_date' => '2026-09-21',
            'status' => CompletedWork::STATUS_PENDING,
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
            'planning_status' => CompletedWork::PLANNING_REQUIRES_SCHEDULE,
        ]);
        $this->scheduleTask($project, $context->user, '2026-09-25', 'Ближайшая кладка');

        $hidden = Project::factory()->create(['organization_id' => $context->organization->id, 'is_archived' => false]);
        $this->scheduleTask($hidden, $context->user, '2026-09-22', 'Скрытая работа');
        CompletedWork::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $hidden->id,
            'quantity' => 3,
            'price' => 900,
            'total_amount' => 2700,
            'completion_date' => '2026-09-21',
            'status' => CompletedWork::STATUS_PENDING,
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
            'planning_status' => CompletedWork::PLANNING_REQUIRES_SCHEDULE,
        ]);

        $response = $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/pto/work-queue');
        $response->assertOk();
        $items = collect($response->json('data'));
        self::assertTrue($items->contains(static fn (array $item): bool => $item['data_complete'] === false));
        self::assertSame(0, $items->where('category', 'risk')->where('data_complete', false)->count());
        $risks = $items->where('category', 'risk');
        self::assertTrue($risks->contains(static fn (array $item): bool => $item['title'] === 'Ближайшая кладка'));
        self::assertFalse($items->contains(static fn (array $item): bool => ($item['project']['id'] ?? null) === $hidden->id));
        self::assertFalse($items->contains(static fn (array $item): bool => $item['title'] === 'Скрытая работа'));
        self::assertSame($items->where('category', 'risk')->count(), (int) $response->json('summary.risk'));
        $this->assertNoFinancialKeys($response->json());
        self::assertGreaterThanOrEqual(1, (int) $response->json('summary.incomplete'));
        $incomplete = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/pto/work-queue?queue=incomplete');
        $incomplete->assertOk();
        self::assertNotEmpty($incomplete->json('data'));
        self::assertTrue(collect($incomplete->json('data'))->every(
            static fn (array $item): bool => $item['data_complete'] === false
        ));
        self::assertFalse(collect($incomplete->json('data'))->contains(
            static fn (array $item): bool => ($item['project']['id'] ?? null) === $hidden->id
        ));
    }

    public function test_queue_filters_pagination_and_returned_set_are_not_overdue_without_due_date(): void
    {
        [$context, $project, $set] = $this->assignedContext();
        $this->allowAccess();
        $requirements = [];
        for ($index = 1; $index <= 30; $index++) {
            $requirements[] = [
                'requirement_key' => 'protocol-'.$index,
                'title' => 'Протокол '.$index,
                'profile_type' => 'system_test_act',
                'stage' => 'document_review',
                'source' => 'Перечень проекта',
                'source_revision' => '1',
                'coverage_scope' => ['project_id' => (int) $project->id],
            ];
        }
        app(ExecutiveDocumentRequirementsService::class)->replaceForSet(
            $set,
            $requirements,
            $context->user,
            app(AuthorizationService::class),
            0,
            't23-page'
        );
        ExecutiveDocumentTransmittal::query()->create([
            'organization_id' => $context->organization->id,
            'document_set_id' => $set->id,
            'transmitted_by' => $context->user->id,
            'transmittal_number' => 'T-1',
            'transmitted_at' => now(),
            'status' => 'returned',
            'manifest' => ['versions' => [['document_id' => 1, 'version_id' => 41]]],
        ]);

        $page1 = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/pto/work-queue?category=blocker&per_page=25&page=1&project_id='.$project->id);
        $page1->assertOk();
        self::assertCount(25, $page1->json('data'));
        self::assertSame(2, (int) $page1->json('meta.last_page'));
        self::assertSame(30, (int) $page1->json('meta.total'));
        $page2 = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/pto/work-queue?category=blocker&per_page=25&page=2&project_id='.$project->id);
        $page2->assertOk();
        self::assertCount(5, $page2->json('data'));

        $returns = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/pto/work-queue?queue=returns&project_id='.$project->id);
        $returns->assertOk();
        self::assertNotEmpty($returns->json('data'));
        self::assertSame('problem', $returns->json('data.0.category'));
        self::assertFalse($returns->json('data.0.priority_basis.overdue'));
        self::assertSame('Срок не назначен', $returns->json('data.0.due_label'));
        self::assertSame(41, (int) $returns->json('data.0.version_id'));
        self::assertSame('retransmit', $returns->json('data.0.next_action.key'));
        $this->assertNoFinancialKeys($returns->json());
    }

    /**
     * @return array{0: AdminApiTestContext, 1: Project, 2: ExecutiveDocumentSet}
     */
    private function assignedContext(): array
    {
        $context = AdminApiTestContext::create();
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'assigned_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id, 'is_archived' => false]);
        $context->user->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
        $set = ExecutiveDocumentSet::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by' => $context->user->id,
            'set_number' => 'PTO-1',
            'title' => 'Комплект ПТО',
            'status' => 'draft',
        ]);

        return [$context, $project, $set];
    }

    private function allowAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });
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

    private function actRequirement(ExecutiveDocumentSet $set, AdminApiTestContext $context): \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentRequirement
    {
        return app(ExecutiveDocumentRequirementsService::class)->create($set, [
            'requirement_key' => 'aosr',
            'title' => 'АОСР',
            'profile_type' => 'hidden_work_act',
            'stage' => 'document_review',
            'source' => 'Перечень проекта',
            'source_revision' => '1',
            'coverage_scope' => ['project_id' => (int) $set->project_id],
            'conditions' => ['designer_supervision' => false, 'separate_executor' => false],
        ], $context->user, app(AuthorizationService::class));
    }

    /**
     * @param  list<string>  $omit
     */
    private function approvedAct(ExecutiveDocumentSet $set, AdminApiTestContext $context, Project $project, array $omit = []): \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion
    {
        $unitId = MeasurementUnit::query()->value('id') ?? MeasurementUnit::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'м²',
            'short_name' => 'м²',
            'type' => 'work',
        ])->id;
        $workType = WorkType::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Армирование',
            'code' => 'T23-REBAR',
            'measurement_unit_id' => $unitId,
            'is_active' => true,
        ]);
        $journal = ConstructionJournal::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'name' => 'Журнал',
            'journal_number' => 'T23',
            'start_date' => '2026-09-20',
            'status' => 'active',
            'created_by_user_id' => $context->user->id,
        ]);
        $entry = $journal->entries()->create([
            'entry_date' => '2026-09-20',
            'entry_number' => 1,
            'work_description' => 'Армирование плиты',
            'status' => 'draft',
            'created_by_user_id' => $context->user->id,
        ]);
        $document = ExecutiveDocument::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'document_set_id' => $set->id,
            'created_by' => $context->user->id,
            'document_type' => 'hidden_work_act',
            'title' => 'АОСР',
            'status' => 'approved',
            'work_type_id' => $workType->id,
            'journal_entry_id' => $entry->id,
            'signatories' => $this->signatories($omit),
        ]);

        return $document->versions()->create([
            'organization_id' => $context->organization->id,
            'uploaded_by' => $context->user->id,
            'version_number' => '1.0',
            'file_url' => 's3://t23/act.pdf',
            'status' => 'approved',
            'content_hash' => hash('sha256', 'act'),
            'profile_snapshot' => [
                'act_number' => '1',
                'presented_works' => 'Армирование плиты',
                'started_at' => '2026-09-19',
                'finished_at' => '2026-09-20',
                'next_works_permission' => 'Разрешено бетонирование',
            ],
            'basis_snapshot' => [
                'profile' => app(ExecutiveDocumentProfileRegistry::class)->require('hidden_work_act'),
                'coverage' => ['project_id' => $project->id],
                'journal_entry_id' => $entry->id,
                'document' => [
                    'work_type_id' => $workType->id,
                    'signatories' => $this->signatories($omit),
                ],
            ],
        ]);
    }

    /**
     * @param  list<string>  $omit
     * @return list<array{role: string, name: string, organization: string, authority_document: string}>
     */
    private function signatories(array $omit): array
    {
        $roles = ['developer_control_representative', 'construction_representative', 'contractor_control_representative'];

        return array_values(array_map(static fn (string $role): array => [
            'role' => $role,
            'name' => 'Представитель',
            'organization' => 'Участник строительства',
            'authority_document' => 'Приказ 1',
        ], array_values(array_filter($roles, static fn (string $role): bool => ! in_array($role, $omit, true)))));
    }

    private function scheduleTask(Project $project, User $user, string $start, string $name): ScheduleTask
    {
        $schedule = ProjectSchedule::query()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'created_by_user_id' => $user->id,
            'planned_start_date' => $start,
            'planned_end_date' => $start,
            'name' => $name.' график',
        ]);

        return ScheduleTask::query()->create([
            'organization_id' => $project->organization_id,
            'schedule_id' => $schedule->id,
            'created_by_user_id' => $user->id,
            'planned_start_date' => $start,
            'planned_end_date' => $start,
            'planned_duration_days' => 1,
            'name' => $name,
            'quantity' => 10,
            'completed_quantity' => 0,
        ]);
    }

    private function assertNoFinancialKeys(mixed $payload): void
    {
        $forbidden = ['total_amount', 'unit_price', 'price', 'estimated_cost', 'actual_cost', 'earned_value', 'resource_cost'];
        $walker = function (mixed $value) use (&$walker, $forbidden): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $child) {
                if (is_string($key)) {
                    self::assertFalse(in_array($key, $forbidden, true), 'Финансовое поле '.$key.' не должно попадать в очередь ПТО');
                }
                $walker($child);
            }
        };
        $walker($payload);
    }
}
