<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantProjectAttentionReader;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantProjectAttentionTest extends TestCase
{
    private AssistantRealAuthorizationFixture $fixture;
    private Project $project;
    private ProjectSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-13 10:00:00');
        Queue::fake();
        config(['cache.default' => 'array']);
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $this->project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id]));
        $this->schedule = $this->schedule($this->project);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_attention_counts_before_limits_has_real_owners_actions_and_no_foreign_project_data(): void
    {
        for ($i = 0; $i < 28; $i++) { $this->task($this->schedule, 'Задача '.$i); }
        $this->task($this->schedule, 'Отменённая задача', ['status' => 'cancelled']);
        $this->defect($this->project, 'Критическое замечание', 'critical');
        $this->defect($this->project, 'Второе критическое замечание', 'critical');
        $this->defect($this->project, 'Просроченное замечание', 'major');
        $this->defect($this->project, 'Устранённое критическое замечание', 'critical', 'resolved');
        $this->purchase($this->project, 'Первая заявка');
        $this->purchase($this->project, 'Вторая заявка');
        $other = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id]));
        $this->task($this->schedule($other), 'Чужой объект');
        $this->defect($other, 'Чужое замечание', 'critical');
        $this->purchase($other, 'Чужая заявка');
        $read = $this->read();
        $groups = array_column($read['data']['groups'], null, 'kind');
        self::assertSame(28, $groups['overdue_schedule']['total']);
        self::assertCount(3, $groups['overdue_schedule']['items']);
        self::assertTrue($groups['overdue_schedule']['has_more']);
        self::assertSame(2, $groups['critical_quality']['total']);
        self::assertSame(1, $groups['overdue_quality']['total']);
        self::assertSame(2, $groups['pending_procurement']['total']);
        self::assertSame($this->fixture->owner->name, $groups['overdue_schedule']['items'][0]['owner_name']);
        self::assertStringContainsString('Следующее действие:', $read['server_formatted_answer']);
        self::assertStringContainsString('2026-10-12', $read['server_formatted_answer']);
        self::assertStringNotContainsString('Чужой', json_encode($read));
        self::assertSame('verified', app(AssistantProjectAttentionReader::class)->verifiedAnswer($read, $this->fixture->owner, $this->fixture->organization->id)['validation_status']);
    }

    public function test_quality_focus_partitions_all_open_defects_before_limiting_examples(): void
    {
        $this->defect($this->project, 'Критическое просроченное', 'critical');
        $this->defect($this->project, 'Другой просроченный дефект', 'major');
        $this->defect($this->project, 'Без срока', 'minor');
        $this->defect($this->project, 'Срок сегодня', 'minor');
        $this->defect($this->project, 'Будущий срок', 'major');
        $this->defect($this->project, 'Закрытое', 'minor', 'resolved');
        $this->defect($this->project, 'Отменённое', 'major', 'cancelled');
        foreach (['Без срока' => null, 'Срок сегодня' => '2026-10-13', 'Будущий срок' => '2026-10-20'] as $title => $date) {
            QualityDefect::query()->where('project_id', $this->project->id)->where('title', $title)->update(['due_date' => $date]);
        }
        $read = app(AssistantProjectAttentionReader::class)->read($this->fixture->owner, $this->fixture->organization->id,
            ['project_id' => $this->project->id, 'focus' => 'quality', 'limit' => 1]);
        $groups = array_column($read['data']['groups'], null, 'kind');
        self::assertSame(1, $groups['critical_quality']['total']);
        self::assertSame(1, $groups['overdue_quality']['total']);
        self::assertSame(3, $groups['other_quality']['total']);
        self::assertCount(1, $groups['other_quality']['items']);
        self::assertTrue($groups['other_quality']['has_more']);
        self::assertStringContainsString('Всего незакрытых замечаний качества: 5.', $read['server_formatted_answer']);
        self::assertStringNotContainsString('Закрытое', $read['server_formatted_answer']);
        self::assertStringNotContainsString('Отменённое', $read['server_formatted_answer']);
        self::assertSame('verified', app(AssistantProjectAttentionReader::class)->verifiedAnswer($read,
            $this->fixture->owner, $this->fixture->organization->id)['validation_status']);
    }

    public function test_receipt_rechecks_assigned_name_deadline_counts_and_current_role(): void
    {
        $task = $this->task($this->schedule, 'Просроченная задача');
        $read = $this->read();
        $guard = app(AssistantSourceReferenceGuard::class);
        self::assertTrue($guard->fresh($this->fixture->owner, $this->fixture->organization->id, $read['source_refs']));
        $this->fixture->owner->updateQuietly(['name' => 'Новое имя ответственного']);
        self::assertFalse($guard->fresh($this->fixture->owner, $this->fixture->organization->id, $read['source_refs']));
        $read = $this->read();
        $task->updateQuietly(['planned_end_date' => '2026-10-20']);
        self::assertFalse($guard->fresh($this->fixture->owner, $this->fixture->organization->id, $read['source_refs']));
        $read = $this->read();
        $this->task($this->schedule, 'Новая просроченная задача');
        self::assertFalse($guard->fresh($this->fixture->owner, $this->fixture->organization->id, $read['source_refs']));
        $read = $this->read();
        UserRoleAssignment::query()->where('user_id', $this->fixture->owner->id)->update(['is_active' => false]);
        self::assertFalse(app(AssistantDataAccessPolicy::class)->canReadReference($this->fixture->owner, $this->fixture->organization->id, $read['source_refs'][0]));
    }

    public function test_revoked_owner_membership_hides_the_name_and_invalidates_the_receipt(): void
    {
        $this->task($this->schedule, 'Проверка ответственного', ['assigned_user_id' => $this->fixture->administrator->id]);
        $read = $this->read();
        self::assertSame($this->fixture->administrator->name, $read['data']['groups'][0]['items'][0]['owner_name']);
        $this->fixture->organization->users()->updateExistingPivot($this->fixture->administrator->id, ['is_active' => false]);
        self::assertFalse(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->owner, $this->fixture->organization->id, $read['source_refs']));
        $current = $this->read();
        self::assertNull($current['data']['groups'][0]['items'][0]['owner_name']);
        self::assertSame('unavailable', $current['data']['groups'][0]['items'][0]['owner_state']);
    }

    public function test_limited_role_does_not_receive_inaccessible_section_counts_or_fake_empty_statement(): void
    {
        $this->defect($this->project, 'Скрытое замечание', 'critical');
        $actor = $this->fixture->addMember(['ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view']]);
        $actor->assignedProjects()->attach($this->project->id, ['role' => 'member', 'is_active' => true]);
        $read = app(AssistantProjectAttentionReader::class)->read($actor, $this->fixture->organization->id, ['project_id' => $this->project->id, 'focus' => 'all', 'limit' => 3]);
        self::assertFalse($read['data']['coverage']['critical_quality']['available']);
        self::assertArrayNotHasKey('total', $read['data']['coverage']['critical_quality']);
        self::assertStringNotContainsString('Скрытое замечание', json_encode($read));
        self::assertStringNotContainsString('проблем не найдено', $read['server_formatted_answer']);
        self::assertStringContainsString('недостаточно', $read['server_formatted_answer']);
    }

    private function read(): array
    {
        return app(AssistantProjectAttentionReader::class)->read($this->fixture->owner, $this->fixture->organization->id,
            ['project_id' => $this->project->id, 'focus' => 'all', 'limit' => 3]);
    }

    private function schedule(Project $project): ProjectSchedule
    {
        return ProjectSchedule::withoutEvents(fn () => ProjectSchedule::query()->create(['organization_id' => $project->organization_id,
            'project_id' => $project->id, 'created_by_user_id' => $this->fixture->owner->id, 'name' => 'График',
            'planned_start_date' => '2026-09-01', 'planned_end_date' => '2026-11-01', 'status' => 'active']));
    }

    private function task(ProjectSchedule $schedule, string $name, array $attributes = []): ScheduleTask
    {
        return ScheduleTask::withoutEvents(fn () => ScheduleTask::query()->create($attributes + ['organization_id' => $schedule->organization_id,
            'schedule_id' => $schedule->id, 'created_by_user_id' => $this->fixture->owner->id, 'name' => $name,
            'assigned_user_id' => $this->fixture->owner->id, 'status' => 'in_progress', 'task_type' => 'task', 'progress_percent' => 10,
            'planned_start_date' => '2026-10-01', 'planned_end_date' => '2026-10-12', 'planned_duration_days' => 11]));
    }

    private function defect(Project $project, string $title, string $severity, string $status = 'open'): void
    {
        QualityDefect::withoutEvents(fn () => QualityDefect::query()->create(['organization_id' => $project->organization_id,
            'project_id' => $project->id, 'defect_number' => (string) \Illuminate\Support\Str::uuid(), 'title' => $title,
            'severity' => $severity, 'status' => $status, 'due_date' => '2026-10-12']));
    }

    private function purchase(Project $project, string $title): void
    {
        Model::withoutEvents(function () use ($project, $title): void {
            $site = SiteRequest::query()->create(['organization_id' => $project->organization_id, 'project_id' => $project->id,
                'user_id' => $this->fixture->owner->id, 'title' => $title, 'status' => 'approved', 'request_type' => 'material_request']);
            PurchaseRequest::query()->create(['organization_id' => $project->organization_id, 'site_request_id' => $site->id,
                'request_number' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'pending']);
        });
    }
}
