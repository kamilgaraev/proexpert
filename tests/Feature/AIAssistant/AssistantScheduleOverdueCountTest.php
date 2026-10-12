<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainReadService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainSearchCountEvidence;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard;
use App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactVerifier;
use App\BusinessModules\Features\AIAssistant\Services\AssistantToolArgumentValidator;
use App\Enums\Schedule\TaskStatusEnum;
use App\Enums\Schedule\TaskTypeEnum;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantScheduleOverdueCountTest extends TestCase
{
    private AssistantRealAuthorizationFixture $fixture;
    private Project $project;
    private ProjectSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = AssistantRealAuthorizationFixture::create();
        $this->fixture->memberRole->update(['module_permissions' => [
            'ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view'],
            'schedule-management' => ['schedule-management.view', 'schedule.view'],
        ]]);
        $this->project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id]));
        $this->fixture->member->assignedProjects()->attach($this->project->id, ['is_active' => true, 'role' => 'member']);
        $this->schedule = $this->schedule($this->project);
    }

    public function test_public_search_counts_exact_canonical_overdue_predicate_before_limit_through_schedule_parent(): void
    {
        for ($i = 0; $i < 23; $i++) { $this->task($this->schedule); }
        $this->task($this->schedule, ['task_type' => TaskTypeEnum::SUMMARY->value]);
        foreach ([['status' => TaskStatusEnum::COMPLETED->value], ['status' => TaskStatusEnum::CANCELLED->value],
            ['progress_percent' => 100], ['planned_end_date' => now()->addDay()->toDateString()]] as $attributes) { $this->task($this->schedule, $attributes); }
        $hidden = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id]));
        $this->task($this->schedule($hidden));
        $foreign = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->foreignOrganization->id]));
        $this->task($this->schedule($foreign));
        $arguments = $this->arguments();
        $tool = app(AIToolRegistry::class)->getTool('assistant_domain_search');
        self::assertNotNull($tool);
        (new AssistantToolArgumentValidator)->validate($arguments, $tool->getParametersSchema());
        $result = $tool->execute($arguments, $this->fixture->member, $this->fixture->organization);
        self::assertIsArray($result);
        self::assertCount(1, $result['results']);
        self::assertSame(24, $result['result_window']['total']);
        self::assertTrue($result['result_window']['has_more']);
        self::assertTrue((new \App\BusinessModules\Features\AIAssistant\Services\AssistantRequestProjectScope(true, $this->project->id))
            ->allowsReferences(\App\BusinessModules\Features\AIAssistant\Services\AssistantRequestProjectScope::references($result), [$result]));
        $proof = (new AssistantStructuredFactVerifier)->guard('Есть на нашем объекте просроченные задачи? Сколько их?',
            'Найдено 1.', [$result], ['project_id' => $this->project->id]);
        self::assertSame('verified', $proof['validation_status']);
        self::assertFalse($proof['needs_clarification']);
        self::assertSame(24, $proof['source_refs'][0]['record_count']);
        self::assertTrue(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->member, $this->fixture->organization->id, $proof['source_refs']));
        $unfiltered = app(AssistantDomainReadService::class)->execute('search', array_replace($arguments, ['predicate' => null]),
            $this->fixture->member, $this->fixture->organization->id);
        self::assertFalse(AssistantDomainSearchCountEvidence::matchesRequestedScope('Сколько просроченных задач в проекте?',
            $unfiltered['domain_search_count_evidence'], ['project_id' => $this->project->id]));
        $next = app(AssistantDomainReadService::class)->execute('search', array_replace($arguments, ['cursor' => $result['result_window']['next_cursor']]),
            $this->fixture->member, $this->fixture->organization->id);
        self::assertNotSame($result['results'][0]['id'], $next['results'][0]['id']);
        self::assertSame(24, $next['result_window']['total']);
    }

    public function test_count_source_rechecks_task_status_and_current_permission(): void
    {
        $task = $this->task($this->schedule);
        $result = app(AssistantDomainReadService::class)->execute('search', $this->arguments(), $this->fixture->member, $this->fixture->organization->id);
        $reference = $result['domain_search_count_evidence']['source_ref'];
        $guard = app(AssistantSourceReferenceGuard::class);
        self::assertTrue($guard->fresh($this->fixture->member, $this->fixture->organization->id, [$reference]));
        $task->update(['status' => TaskStatusEnum::COMPLETED->value]);
        self::assertFalse($guard->fresh($this->fixture->member, $this->fixture->organization->id, [$reference]));
        $zero = app(AssistantDomainReadService::class)->execute('search', $this->arguments(), $this->fixture->member, $this->fixture->organization->id);
        self::assertSame(0, $zero['domain_search_count_evidence']['value']);
        self::assertSame('verified', (new AssistantStructuredFactVerifier)->guard('Сколько просроченных задач в текущем проекте?',
            'Нет задач.', [$zero], ['project_id' => $this->project->id])['validation_status']);
        $this->fixture->memberRole->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view']]]);
        self::assertFalse($guard->fresh($this->fixture->member, $this->fixture->organization->id, [$zero['domain_search_count_evidence']['source_ref']]));
    }

    public function test_hidden_requested_project_is_denied_instead_of_verified_zero(): void
    {
        $hidden = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id]));
        $this->expectException(AccessDeniedHttpException::class);
        app(AssistantDomainReadService::class)->execute('search', array_replace($this->arguments(), ['project_id' => $hidden->id]),
            $this->fixture->member, $this->fixture->organization->id);
    }

    public function test_continuation_cannot_switch_predicate_or_reuse_another_clock_day(): void
    {
        $this->task($this->schedule);
        $this->task($this->schedule);
        $result = app(AssistantDomainReadService::class)->execute('search', $this->arguments(), $this->fixture->member, $this->fixture->organization->id);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(AssistantDomainReadService::class)->execute('search', array_replace($this->arguments(), ['predicate' => null,
            'cursor' => $result['result_window']['next_cursor']]), $this->fixture->member, $this->fixture->organization->id);
    }

    private function arguments(): array
    {
        return ['domain' => 'schedule', 'entity_type' => 'schedule_task', 'predicate' => 'overdue', 'query' => '',
            'project_id' => $this->project->id, 'limit' => 1, 'cursor' => null, 'fields' => ['id', 'name', 'status', 'planned_end_date']];
    }

    public function test_task_project_reference_uses_current_authorized_parent_and_rejects_forged_or_moved_scope(): void
    {
        $this->task($this->schedule);
        $result = app(AssistantDomainReadService::class)->execute('search', array_replace($this->arguments(), ['fields' => ['id', 'name']]),
            $this->fixture->member, $this->fixture->organization->id);
        $reference = array_values(array_filter($result['source_refs'], static fn (array $ref): bool => $ref['entity_type'] === 'schedule_task'))[0];
        self::assertSame($this->project->id, $reference['project_id']);
        self::assertNotContains('schedule_id', $reference['checked_fields']);
        $guard = app(AssistantSourceReferenceGuard::class);
        self::assertTrue($guard->fresh($this->fixture->member, $this->fixture->organization->id, [$reference]));
        $other = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id]));
        $this->fixture->member->assignedProjects()->attach($other->id, ['is_active' => true, 'role' => 'member']);
        self::assertFalse($guard->fresh($this->fixture->member, $this->fixture->organization->id, [array_replace($reference, ['project_id' => $other->id])]));
        $this->schedule->update(['project_id' => $other->id]);
        self::assertFalse($guard->fresh($this->fixture->member, $this->fixture->organization->id, [$reference]));
    }

    private function schedule(Project $project): ProjectSchedule
    {
        return ProjectSchedule::withoutEvents(fn () => ProjectSchedule::query()->create(['organization_id' => $project->organization_id,
            'project_id' => $project->id, 'created_by_user_id' => $this->fixture->owner->id, 'name' => 'График проверки',
            'planned_start_date' => now()->subMonth()->toDateString(), 'planned_end_date' => now()->addMonth()->toDateString(), 'status' => 'draft']));
    }

    private function task(ProjectSchedule $schedule, array $attributes = []): ScheduleTask
    {
        return ScheduleTask::withoutEvents(fn () => ScheduleTask::query()->create($attributes + [
            'organization_id' => $schedule->organization_id, 'schedule_id' => $schedule->id,
            'created_by_user_id' => $this->fixture->owner->id, 'name' => 'Просроченная задача',
            'status' => TaskStatusEnum::IN_PROGRESS->value, 'task_type' => TaskTypeEnum::TASK->value, 'progress_percent' => 10,
            'planned_start_date' => now()->subWeek()->toDateString(), 'planned_end_date' => now()->subDay()->toDateString(), 'planned_duration_days' => 6,
        ]));
    }
}
