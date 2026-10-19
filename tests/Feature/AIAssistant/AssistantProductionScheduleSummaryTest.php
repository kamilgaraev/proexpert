<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\Domains\GetProjectProductionScheduleSummaryTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\SearchProjectsTool;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantNamedProjectReadScope;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestProjectScope;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequiredProjectRead;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectProductionScheduleReader;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\CompletedWork;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\User;
use App\Models\WorkType;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantProductionScheduleSummaryTest extends TestCase
{
    private AssistantRealAuthorizationFixture $fixture;

    private Project $project;

    private WorkType $workType;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 10:00:00');
        Queue::fake();
        config(['cache.default' => 'array']);
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $this->project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id,
            'name' => 'Учебный ПТО', 'is_archived' => false, 'end_date' => '2026-10-13']));
        $this->workType = WorkType::withoutEvents(fn () => WorkType::query()->create(['organization_id' => $this->fixture->organization->id,
            'name' => 'Физическая работа', 'code' => 'PRODUCTION-SCHEDULE', 'is_active' => true]));
        self::assertSame(1, DB::transactionLevel());
        DB::commit();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if (DB::transactionLevel() === 0) {
            DB::beginTransaction();
        }
        parent::tearDown();
    }

    public function test_full_aggregates_precede_examples_and_keep_units_and_schedule_statuses_separate(): void
    {
        $first = $this->work('0.1000', 'м²');
        $this->work('0.2000', 'м²');
        $this->work('1.0000', 'шт');
        $this->work('9.0000', null);
        $this->work('500.0000', 'м²', ['additional_info' => ['fact_kind' => 'equipment', 'unit_of_measurement' => 'м²']]);
        $this->work('999.0000', 'м²', ['status' => 'draft']);
        $other = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id]));
        $this->work('777.0000', 'м²', ['project_id' => $other->id]);
        $schedule = $this->schedule();
        $overdue = $this->task($schedule, 'Контрольная веха', ['task_type' => 'milestone', 'planned_end_date' => '2026-10-13']);
        for ($index = 0; $index < 25; $index++) {
            $this->task($schedule, 'Открытая задача '.$index);
        }
        for ($index = 0; $index < 3; $index++) {
            $this->task($schedule, 'Завершённая задача '.$index, ['status' => 'completed', 'progress_percent' => 100]);
        }
        $this->task($schedule, 'Суммарная задача', ['task_type' => 'summary', 'status' => 'completed', 'progress_percent' => 100]);
        for ($index = 0; $index < 4; $index++) {
            $this->task($this->schedule('draft'), 'Черновая задача', ['planned_end_date' => '2026-10-01']);
        }
        $this->task($this->schedule('active', $other), 'Задача другого проекта', ['planned_end_date' => '2026-10-01']);
        $before = [$first->fresh()->getAttributes(), $overdue->fresh()->getAttributes(), $schedule->fresh()->getAttributes()];
        $reader = app(AssistantProjectProductionScheduleReader::class);
        $read = $reader->read($this->fixture->owner, $this->fixture->organization->id, ['project_id' => $this->project->id]);
        $data = $read['data'];
        self::assertSame(4, $data['confirmed_works']);
        self::assertSame(5, $data['total_schedules']);
        self::assertSame(1, $data['active_schedules']);
        self::assertSame(34, $data['total_tasks']);
        self::assertSame(29, $data['active_work_tasks']);
        self::assertSame(26, $data['open_tasks']);
        self::assertSame(1, $data['overdue_tasks']);
        self::assertSame(26, $data['unassigned_open_tasks']);
        self::assertCount(20, $data['examples']);
        self::assertSame($overdue->id, $data['examples'][0]['id']);
        self::assertSame('unassigned', $data['examples'][0]['owner_state']);
        self::assertSame('не назначен', $data['examples'][0]['owner']);
        $volumes = collect($data['accepted_volumes'])->keyBy('unit');
        self::assertSame('0.3000', $volumes['м²']['quantity']);
        self::assertSame('1.0000', $volumes['шт']['quantity']);
        self::assertNull($volumes['']['quantity']);
        self::assertFalse($data['volume_groups_limited']);
        self::assertSame('2026-10-13', $data['project_end_on']);
        self::assertStringContainsString('завершение работ не подтверждено', $read['server_formatted_answer']);
        self::assertStringContainsString('Показано 20 из 26', $read['server_formatted_answer']);
        self::assertStringNotContainsString('Черновая задача', $read['server_formatted_answer']);
        self::assertStringNotContainsString('другого проекта', $read['server_formatted_answer']);
        self::assertSame($before, [$first->fresh()->getAttributes(), $overdue->fresh()->getAttributes(), $schedule->fresh()->getAttributes()]);
        self::assertNotNull($reader->verifiedAnswer($read, $this->fixture->owner, $this->fixture->organization->id));
        $first->updateQuietly(['completed_quantity' => '0.4000']);
        self::assertFalse(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->owner, $this->fixture->organization->id, $read['source_refs']));
        $fresh = $reader->read($this->fixture->owner, $this->fixture->organization->id, ['project_id' => $this->project->id]);
        UserRoleAssignment::query()->where('user_id', $this->fixture->owner->id)->update(['is_active' => false]);
        self::assertFalse(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->owner, $this->fixture->organization->id, $fresh['source_refs']));
    }

    public function test_period_filters_physical_work_dates_and_survives_reference_recheck(): void
    {
        $this->work('0.1000', 'м²', ['completion_date' => '2026-10-01']);
        $this->work('0.2000', 'м²', ['completion_date' => '2026-10-31']);
        $outside = $this->work('100.0000', 'м²', ['completion_date' => '2026-09-30']);
        $this->work('200.0000', 'м²', ['completion_date' => '2026-11-01']);
        $this->task($this->schedule(), 'Текущая контрольная веха', ['task_type' => 'milestone']);
        $arguments = ['project_id' => $this->project->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31'];
        $reader = app(AssistantProjectProductionScheduleReader::class);
        $read = $reader->read($this->fixture->owner, $this->fixture->organization->id, $arguments);
        self::assertSame(2, $read['data']['confirmed_works']);
        self::assertSame('0.3000', $read['data']['accepted_volumes'][0]['quantity']);
        self::assertSame(1, $read['data']['open_tasks']);
        self::assertStringContainsString('2026-10-01', $read['server_formatted_answer']);
        self::assertStringContainsString('2026-10-31', $read['server_formatted_answer']);
        self::assertStringContainsString('текущем состоянии всего проекта', $read['server_formatted_answer']);
        self::assertTrue(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->owner, $this->fixture->organization->id, $read['source_refs']));
        self::assertNotNull($reader->verifiedAnswer($read, $this->fixture->owner, $this->fixture->organization->id));
        $tampered = $read['source_refs'][0];
        $tampered['_read_arguments']['period_start'] = '2026-09-01';
        self::assertFalse($reader->matchesReference($this->fixture->owner, $this->fixture->organization->id, $tampered));
        $outside->updateQuietly(['completion_date' => '2026-10-15']);
        self::assertFalse(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->owner, $this->fixture->organization->id, $read['source_refs']));
    }

    public function test_open_project_start_period_filters_future_works_and_rechecks_its_upper_bound(): void
    {
        $this->work('0.1000', 'м²', ['completion_date' => '2020-01-01']);
        $this->work('0.2000', 'м²', ['completion_date' => '2026-10-18']);
        $future = $this->work('999.0000', 'м²', ['completion_date' => '2026-10-19']);
        $reader = app(AssistantProjectProductionScheduleReader::class);
        $read = $reader->read($this->fixture->owner, $this->fixture->organization->id, [
            'project_id' => $this->project->id, 'period_start' => null, 'period_end' => '2026-10-18',
        ]);
        self::assertSame(2, $read['data']['confirmed_works']);
        self::assertSame('0.3000', $read['data']['accepted_volumes'][0]['quantity']);
        self::assertNull($read['data']['period_start']);
        self::assertSame('2026-10-18', $read['data']['period_end']);
        self::assertStringContainsString('с начала учёта проекта', $read['server_formatted_answer']);
        self::assertStringContainsString('2026-10-18', $read['server_formatted_answer']);
        self::assertFalse($reader->verifiedAnswer($read, $this->fixture->owner, $this->fixture->organization->id)['needs_clarification']);
        self::assertTrue($reader->matchesReference($this->fixture->owner, $this->fixture->organization->id, $read['source_refs'][0]));
        $tampered = $read['source_refs'][0];
        $tampered['_read_arguments']['period_end'] = '2026-10-19';
        self::assertFalse($reader->matchesReference($this->fixture->owner, $this->fixture->organization->id, $tampered));
        $future->updateQuietly(['completion_date' => '2026-10-18']);
        self::assertFalse($reader->matchesReference($this->fixture->owner, $this->fixture->organization->id, $read['source_refs'][0]));
    }

    public function test_production_read_uses_moscow_snapshot_date_and_deadline_cutoff_with_utc_application_timezone(): void
    {
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow(new Carbon('2026-10-18 21:30:00', 'UTC'));
        \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-10-18 21:30:00', 'UTC'));
        try {
            self::assertSame('2026-10-18', today()->toDateString());
            $this->work('0.1000', 'м²', ['completion_date' => '2026-10-19']);
            $this->work('999.0000', 'м²', ['completion_date' => '2026-10-18']);
            $schedule = $this->schedule();
            $this->task($schedule, 'Вчерашний срок по Москве', ['planned_end_date' => '2026-10-18']);
            $this->task($schedule, 'Сегодняшняя задача', ['planned_end_date' => '2026-10-19']);
            self::assertSame(0, ScheduleTask::query()->where('schedule_id', $schedule->id)->overdue()->count());
            self::assertSame(1, ScheduleTask::query()->where('schedule_id', $schedule->id)
                ->overdue(new \DateTimeImmutable('2026-10-19 00:30:00', new \DateTimeZone('Europe/Moscow')))->count());
            $arguments = \App\BusinessModules\Features\AIAssistant\Services\AssistantProductionSchedulePeriod::arguments((int) $this->project->id,
                ['request' => ['message' => 'Сверь выполненные работы с графиком сегодня']]);
            $reader = app(AssistantProjectProductionScheduleReader::class);
            $read = $reader->read($this->fixture->owner, $this->fixture->organization->id, $arguments);
            self::assertSame('2026-10-19', $read['data']['as_of_date']);
            self::assertSame('2026-10-19', $read['data']['period_start']);
            self::assertSame('2026-10-19', $read['data']['period_end']);
            self::assertSame(1, $read['data']['confirmed_works']);
            self::assertSame(1, $read['data']['overdue_tasks']);
            self::assertStringContainsString('московское время', $read['server_formatted_answer']);
            self::assertTrue($reader->matchesReference($this->fixture->owner, $this->fixture->organization->id, $read['source_refs'][0]));
            Carbon::setTestNow(new Carbon('2026-10-19 21:30:00', 'UTC'));
            \Carbon\CarbonImmutable::setTestNow(new \Carbon\CarbonImmutable('2026-10-19 21:30:00', 'UTC'));
            self::assertFalse($reader->matchesReference($this->fixture->owner, $this->fixture->organization->id, $read['source_refs'][0]));
        } finally {
            date_default_timezone_set($previousTimezone);
            Carbon::setTestNow();
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_unresolved_period_requests_dates_without_showing_all_time_totals(): void
    {
        $this->work('999.0000', 'м²');
        $reader = app(AssistantProjectProductionScheduleReader::class);
        $read = $reader->read($this->fixture->owner, $this->fixture->organization->id, [
            'project_id' => $this->project->id, 'period_start' => null, 'period_end' => null,
        ]);
        self::assertArrayNotHasKey('confirmed_works', $read['data']);
        self::assertStringContainsString('Уточните год', $read['server_formatted_answer']);
        self::assertStringNotContainsString('999', $read['server_formatted_answer']);
        self::assertTrue($reader->verifiedAnswer($read, $this->fixture->owner, $this->fixture->organization->id)['needs_clarification']);
        $read['data']['period_unresolved'] = false;
        self::assertTrue($reader->verifiedAnswer($read, $this->fixture->owner, $this->fixture->organization->id)['needs_clarification']);
    }

    public function test_missing_active_schedule_is_not_completion_and_volume_groups_are_bounded(): void
    {
        for ($index = 0; $index < 23; $index++) {
            $this->work('1.0000', 'единица-'.$index);
        }
        $this->task($this->schedule('draft'), 'Черновик');
        $read = app(AssistantProjectProductionScheduleReader::class)->read($this->fixture->owner, $this->fixture->organization->id, ['project_id' => $this->project->id]);
        self::assertSame(23, $read['data']['confirmed_works']);
        self::assertCount(20, $read['data']['accepted_volumes']);
        self::assertTrue($read['data']['volume_groups_limited']);
        self::assertSame(0, $read['data']['open_tasks']);
        self::assertStringContainsString('Действующих графиков нет', $read['server_formatted_answer']);
        self::assertStringContainsString('Групп больше', $read['server_formatted_answer']);
    }

    public function test_assigned_but_unreadable_owner_is_not_reported_as_unassigned(): void
    {
        $foreign = User::factory()->create(['name' => 'SECRET-FOREIGN-OWNER', 'is_active' => true]);
        $this->task($this->schedule(), 'Задача с закрытым ответственным', ['assigned_user_id' => $foreign->id]);
        $read = app(AssistantProjectProductionScheduleReader::class)->read($this->fixture->owner, $this->fixture->organization->id, ['project_id' => $this->project->id]);
        self::assertSame(0, $read['data']['unassigned_open_tasks']);
        self::assertSame('unavailable', $read['data']['examples'][0]['owner_state']);
        self::assertNull($read['data']['examples'][0]['owner']);
        self::assertArrayNotHasKey('assigned_user_id', $read['data']['examples'][0]);
        self::assertStringContainsString('данные об ответственном недоступны', $read['server_formatted_answer']);
        self::assertStringNotContainsString('SECRET-FOREIGN-OWNER', json_encode($read, JSON_THROW_ON_ERROR));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('namedProjectScopeCases')]
    public function test_named_project_lookup_and_reader_recheck_current_assignment_without_ui_fallback(string $name, bool $quoted): void
    {
        $this->project->updateQuietly(['name' => $name]);
        $this->fixture->memberRole->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.chat'],
            'project-management' => ['projects.view'], 'contract-management' => ['contracts.completed_works.view'], 'schedule-management' => ['schedule.view']]]);
        $ui = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id, 'name' => 'Другой проект']));
        foreach ([$this->project, $ui] as $project) {
            $this->fixture->member->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        }
        Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id, 'name' => $name]));
        Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->foreignOrganization->id, 'name' => $name]));
        $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта '.($quoted ? '«'.$name.'»' : $name).'.',
            'context' => ['entity_refs' => [['type' => 'project', 'id' => $ui->id]]]]];
        $policy = app(AssistantDataAccessPolicy::class);
        $search = fn (array $arguments) => $policy->withCurrentChecks($this->fixture->member, $this->fixture->organization->id,
            fn () => app(SearchProjectsTool::class)->execute($arguments, $this->fixture->member, $this->fixture->organization), true);
        $resolved = AssistantNamedProjectReadScope::bind($plan, $search);
        self::assertSame($this->project->id, AssistantRequestProjectScope::fromPlan($resolved)->projectId);
        self::assertSame($ui->id, $resolved['request']['context']['entity_refs'][0]['id']);
        $this->fixture->member->assignedProjects()->updateExistingPivot($this->project->id, ['is_active' => false]);
        $denied = AssistantNamedProjectReadScope::bind($plan, $search);
        self::assertNull(AssistantRequestProjectScope::fromPlan($denied)->projectId);
        self::assertNull(AssistantRequiredProjectRead::fromPlan($denied));
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(AssistantProjectProductionScheduleReader::class)->read($this->fixture->member, $this->fixture->organization->id, ['project_id' => $this->project->id]);
    }

    public static function namedProjectScopeCases(): array
    {
        return [['Учебный ПТО', true], ['За рекой', false], ['С видом на парк', false]];
    }

    public function test_missing_work_permission_does_not_turn_into_a_zero_works_summary(): void
    {
        $member = $this->fixture->addMember(['ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view'],
            'schedule-management' => ['schedule.view']]);
        $member->assignedProjects()->attach($this->project->id, ['is_active' => true, 'role' => 'member']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);
        app(AssistantProjectProductionScheduleReader::class)->read($member, $this->fixture->organization->id, ['project_id' => $this->project->id]);
    }

    public function test_exact_named_project_is_resolved_beyond_fuzzy_window_and_normalized_duplicates_stay_ambiguous(): void
    {
        foreach (['Дом 1', 'Дом 2', 'Дом 3'] as $name) {
            Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id, 'name' => $name]));
        }
        $exact = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id, 'name' => 'Дом']));
        Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->foreignOrganization->id, 'name' => 'Дом']));
        $plan = ['request' => ['message' => 'Сверь выполненные работы с графиком проекта «Дом».',
            'context' => ['entity_refs' => [['type' => 'project', 'id' => $this->project->id]]]]];
        $calls = [];
        $search = function (array $arguments) use (&$calls): array {
            $calls[] = $arguments;

            return app(AssistantDataAccessPolicy::class)->withCurrentChecks($this->fixture->owner, $this->fixture->organization->id,
                fn () => app(SearchProjectsTool::class)->execute($arguments, $this->fixture->owner, $this->fixture->organization), true);
        };
        $fuzzy = $search(['query' => 'Дом', 'limit' => 2]);
        self::assertTrue($fuzzy['result_window']['has_more']);
        self::assertNotContains($exact->id, array_column($fuzzy['results'], 'id'));
        $calls = [];
        $resolved = AssistantNamedProjectReadScope::bind($plan, $search);
        self::assertSame($exact->id, AssistantRequestProjectScope::fromPlan($resolved)->projectId);
        self::assertSame([['query' => 'Дом', 'limit' => 2, 'name_match' => 'exact']], $calls);
        $exact->update(['name' => "  СЕВЕРНЫЙ\t дом  "]);
        $normalized = $search(['query' => 'северный   ДОМ', 'limit' => 2, 'name_match' => 'exact']);
        self::assertSame([$exact->id], array_column($normalized['results'], 'id'));
        $exact->update(['name' => 'Дом']);
        Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id, 'name' => '  доМ  ']));
        self::assertNull(AssistantRequestProjectScope::fromPlan(AssistantNamedProjectReadScope::bind($plan, $search))->projectId);
    }

    public function test_registered_tool_is_exposed_and_executable_only_with_current_domains_and_project_access(): void
    {
        $checker = app(AIPermissionChecker::class);
        $registry = app(AIToolRegistry::class);
        $name = GetProjectProductionScheduleSummaryTool::NAME;
        self::assertTrue($checker->hasExplicitToolPolicy($name));
        self::assertTrue($checker->canExposeTool($this->fixture->owner, $name));
        self::assertTrue($checker->canExecuteTool($this->fixture->owner, $name, ['project_id' => $this->project->id]));
        $definitions = $registry->getToolsDefinitions(array_values(array_filter([$name], fn (string $tool): bool => $checker->canExposeTool($this->fixture->owner, $tool))), true);
        self::assertCount(1, $definitions);
        self::assertSame($name, $definitions[0]['name']);
        self::assertInstanceOf(GetProjectProductionScheduleSummaryTool::class, $registry->getTool($name));
        $read = $registry->getTool($name)->execute(['project_id' => $this->project->id], $this->fixture->owner, $this->fixture->organization);
        self::assertSame($this->project->id, $read['data']['project']['id']);
        self::assertSame(0, $read['data']['confirmed_works']);
        $foreign = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->foreignOrganization->id]));
        self::assertFalse($checker->canExecuteTool($this->fixture->owner, $name, ['project_id' => $foreign->id]));
        $grants = ['ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view'], 'contract-management' => ['contracts.completed_works.view'],
            'schedule-management' => ['schedule.view']];
        $this->fixture->memberRole->update(['module_permissions' => $grants]);
        self::assertFalse($checker->canExecuteTool($this->fixture->member, $name, ['project_id' => $this->project->id]));
        $this->fixture->member->assignedProjects()->attach($this->project->id, ['is_active' => true, 'role' => 'member']);
        self::assertTrue($checker->canExposeTool($this->fixture->member, $name));
        self::assertTrue($checker->canExecuteTool($this->fixture->member, $name, ['project_id' => $this->project->id]));
        $memberRead = $registry->getTool($name)->execute(['project_id' => $this->project->id], $this->fixture->member, $this->fixture->organization);
        self::assertSame($this->project->id, $memberRead['data']['project']['id']);
        foreach (['works', 'schedule'] as $domain) {
            $restricted = $grants;
            if ($domain === 'works') {
                $restricted['contract-management'] = [];
            } else {
                $restricted['schedule-management'] = [];
            }
            $this->fixture->memberRole->update(['module_permissions' => $restricted]);
            self::assertFalse($checker->canExposeTool($this->fixture->member, $name));
            self::assertFalse($checker->canExecuteTool($this->fixture->member, $name, ['project_id' => $this->project->id]));
        }
    }

    private function work(string $quantity, ?string $unit, array $overrides = []): CompletedWork
    {
        return CompletedWork::withoutEvents(fn () => CompletedWork::query()->create(array_replace([
            'organization_id' => $this->fixture->organization->id, 'project_id' => $this->project->id, 'user_id' => $this->fixture->owner->id,
            'work_type_id' => $this->workType->id, 'quantity' => '100.0000', 'completed_quantity' => $quantity,
            'price' => 0, 'total_amount' => 0, 'completion_date' => '2026-10-13', 'status' => 'confirmed',
            'additional_info' => ['work_name' => 'Физическая работа', 'unit_of_measurement' => $unit],
        ], $overrides)));
    }

    private function schedule(string $status = 'active', ?Project $project = null): ProjectSchedule
    {
        $project ??= $this->project;

        return ProjectSchedule::withoutEvents(fn () => ProjectSchedule::query()->create(['organization_id' => $project->organization_id,
            'project_id' => $project->id, 'created_by_user_id' => $this->fixture->owner->id, 'name' => 'График', 'status' => $status,
            'planned_start_date' => '2026-10-01', 'planned_end_date' => '2026-10-20']));
    }

    private function task(ProjectSchedule $schedule, string $name, array $overrides = []): ScheduleTask
    {
        return ScheduleTask::withoutEvents(fn () => ScheduleTask::query()->create(array_replace(['organization_id' => $schedule->organization_id,
            'schedule_id' => $schedule->id, 'created_by_user_id' => $this->fixture->owner->id, 'name' => $name, 'status' => 'not_started',
            'task_type' => 'task', 'progress_percent' => 0, 'planned_start_date' => '2026-10-01',
            'planned_end_date' => '2026-10-20', 'planned_duration_days' => 19], $overrides)));
    }
}
