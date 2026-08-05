<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\BudgetEstimates\Services\MobileBudgetEstimateService;
use App\BusinessModules\Features\TimeTracking\Services\MobileTimeTrackingService;
use App\BusinessModules\Features\WorkforceManagement\Services\WorkforceAttendanceQrService;
use App\Enums\UserProjectAccessMode;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Mobile\MobileConstructionJournalService;
use App\Services\Mobile\MobileProjectAccessResolver;
use App\Services\Mobile\MobileProjectScheduleService;
use DomainException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MobileProjectAccessResolverTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 5).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->softDeletes();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('current_organization_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->softDeletes();
        });
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->string('name');
            $table->boolean('is_archived')->default(false);
            $table->softDeletes();
        });
        Schema::create('organization_user', function (Blueprint $table): void {
            $table->foreignId('organization_id');
            $table->foreignId('user_id');
            $table->boolean('is_active')->default(true);
            $table->string('project_access_mode');
        });
        Schema::create('project_organization', function (Blueprint $table): void {
            $table->foreignId('project_id');
            $table->foreignId('organization_id');
            $table->boolean('is_active')->default(true);
        });
        Schema::create('project_user', function (Blueprint $table): void {
            $table->foreignId('project_id');
            $table->foreignId('user_id');
            $table->string('role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('assigned_at')->nullable();
        });
        Schema::create('project_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('organization_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->decimal('overall_progress_percent')->default(0);
            $table->string('health_status')->nullable();
            $table->date('planned_start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            $table->integer('planned_duration_days')->nullable();
            $table->date('actual_start_date')->nullable();
            $table->date('actual_end_date')->nullable();
            $table->boolean('critical_path_calculated')->default(false);
            $table->integer('critical_path_duration_days')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('schedule_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('schedule_id');
            $table->string('status')->default('not_started');
            $table->date('planned_end_date')->nullable();
            $table->softDeletes();
        });
        Schema::create('estimates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('project_id');
            $table->foreignId('parent_estimate_id')->nullable();
            $table->string('name');
            $table->string('number');
            $table->string('status');
            $table->date('estimate_date');
            $table->decimal('total_amount')->default(0);
            $table->decimal('total_amount_with_vat')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('estimate_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('estimate_id');
            $table->softDeletes();
        });
        Schema::create('estimate_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('estimate_id');
            $table->string('position_number')->nullable();
            $table->softDeletes();
        });
        Schema::create('time_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('user_id');
            $table->foreignId('project_id');
            $table->foreignId('work_type_id')->nullable();
            $table->foreignId('task_id')->nullable();
            $table->foreignId('approved_by_user_id')->nullable();
            $table->date('work_date');
            $table->string('status')->default('draft');
            $table->decimal('hours_worked')->nullable();
            $table->boolean('is_billable')->default(false);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->json('custom_fields')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('work_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });
        Schema::create('workforce_employees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('user_id')->nullable();
            $table->string('personnel_number');
            $table->string('last_name');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('employment_status')->default('active');
            $table->date('hire_date')->nullable();
            $table->date('dismissal_date')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('workforce_attendance_qr_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('employee_id');
            $table->foreignId('project_id')->nullable();
            $table->date('work_date');
            $table->string('token_hash');
            $table->string('payload_hash');
            $table->timestamp('expires_at');
            $table->string('status');
            $table->foreignId('created_by_user_id');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('used_by_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function test_owner_organization_can_resolve_its_active_project(): void
    {
        [$organization, $user] = $this->userInOrganization(UserProjectAccessMode::ALL_PROJECTS);
        $project = $this->project($organization->id);

        $resolved = $this->resolver()->resolve($user, $organization->id, $project->id, 'Недоступно');

        self::assertTrue($resolved->is($project));
    }

    public function test_active_participant_with_all_projects_access_can_resolve_project(): void
    {
        [$organization, $user] = $this->userInOrganization(UserProjectAccessMode::ALL_PROJECTS);
        $project = $this->participantProject($organization);

        $resolved = $this->resolver()->resolve($user, $organization->id, $project->id, 'Недоступно');

        self::assertTrue($resolved->is($project));
    }

    public function test_assigned_projects_mode_requires_active_assignment(): void
    {
        [$organization, $user] = $this->userInOrganization(UserProjectAccessMode::ASSIGNED_PROJECTS);
        $project = $this->participantProject($organization);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Проект недоступен');

        $this->resolver()->resolve($user, $organization->id, $project->id, 'Проект недоступен');
    }

    public function test_assigned_projects_mode_accepts_active_assignment(): void
    {
        [$organization, $user] = $this->userInOrganization(UserProjectAccessMode::ASSIGNED_PROJECTS);
        $project = $this->participantProject($organization);
        $unassignedProject = $this->participantProject($organization);
        DB::table('project_user')->insert([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'role' => 'member',
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        $resolved = $this->resolver()->resolve($user, $organization->id, $project->id, 'Недоступно');
        $visibleIds = $this->resolver()->query($user, $organization->id)->pluck('projects.id')->all();

        self::assertTrue($resolved->is($project));
        self::assertSame([$project->id], $visibleIds);
        self::assertNotContains($unassignedProject->id, $visibleIds);
    }

    public function test_foreign_or_archived_project_is_not_resolved(): void
    {
        [$organization, $user] = $this->userInOrganization(UserProjectAccessMode::ALL_PROJECTS);
        $foreignOrganization = $this->organization('Чужая организация');
        $foreignProject = $this->project($foreignOrganization->id, true);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Проект недоступен');

        $this->resolver()->assert($user, $organization->id, $foreignProject->id, 'Проект недоступен');
    }

    public function test_construction_journal_accepts_project_of_active_participant(): void
    {
        [$organization, $user] = $this->userInOrganization(UserProjectAccessMode::ALL_PROJECTS);
        $project = $this->participantProject($organization);

        $resolved = $this->app
            ->make(MobileConstructionJournalService::class)
            ->resolveProject($user, $project->id);

        self::assertTrue($resolved->is($project));
    }

    public function test_schedule_list_accepts_project_of_active_participant(): void
    {
        [$organization, $user] = $this->userInOrganization(UserProjectAccessMode::ALL_PROJECTS);
        $project = $this->participantProject($organization);
        $scheduleId = DB::table('project_schedules')->insertGetId([
            'project_id' => $project->id,
            'organization_id' => $project->organization_id,
            'name' => 'График владельца проекта',
            'status' => 'active',
            'planned_start_date' => '2026-08-01',
            'planned_end_date' => '2026-08-31',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $this->app->make(MobileProjectScheduleService::class)->list($user, $project->id);

        self::assertSame($project->id, $payload['project']['id']);
        self::assertSame($scheduleId, $payload['schedules'][0]['id']);
    }

    public function test_budget_estimates_accept_project_and_show_project_estimate_to_active_participant(): void
    {
        [$organization, $user] = $this->userInOrganization(UserProjectAccessMode::ALL_PROJECTS);
        $project = $this->participantProject($organization);
        $estimateId = DB::table('estimates')->insertGetId([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'name' => 'Смета владельца проекта',
            'number' => 'EST-1',
            'status' => 'approved',
            'estimate_date' => '2026-08-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $page = $this->app->make(MobileBudgetEstimateService::class)->paginateEstimates(
            $organization->id,
            $user,
            ['project_id' => $project->id],
            20,
        );

        self::assertSame($estimateId, $page->paginator->items()[0]->id);

        $estimate = $this->app->make(MobileBudgetEstimateService::class)->findEstimate(
            $organization->id,
            $user,
            $estimateId,
        );

        self::assertSame($estimateId, $estimate->id);
    }

    public function test_time_tracking_accepts_project_of_active_participant(): void
    {
        [$organization, $user] = $this->userInOrganization(UserProjectAccessMode::ALL_PROJECTS);
        $project = $this->participantProject($organization);

        $summary = $this->app->make(MobileTimeTrackingService::class)->dailySummary(
            $organization->id,
            $user,
            '2026-08-05',
            $project->id,
        );

        self::assertSame($project->id, $summary['project_id']);
        self::assertSame(0, $summary['totals']['entries_count']);
    }

    public function test_workforce_qr_accepts_project_of_active_participant(): void
    {
        [$organization, $user] = $this->userInOrganization(UserProjectAccessMode::ALL_PROJECTS);
        $project = $this->participantProject($organization);
        DB::table('workforce_employees')->insert([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'personnel_number' => 'MOBILE-1',
            'last_name' => 'Иванов',
            'first_name' => 'Иван',
            'employment_status' => 'active',
            'hire_date' => '2026-08-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $this->app->make(WorkforceAttendanceQrService::class)->issue(
            $organization->id,
            $user,
            ['project_id' => $project->id, 'work_date' => '2026-08-05'],
        );

        self::assertSame($project->id, $payload['project_id']);
        self::assertSame($project->name, $payload['project_label']);
    }

    private function resolver(): MobileProjectAccessResolver
    {
        return $this->app->make(MobileProjectAccessResolver::class);
    }

    private function userInOrganization(UserProjectAccessMode $mode): array
    {
        $organization = $this->organization('Текущая организация');
        $userId = DB::table('users')->insertGetId(['current_organization_id' => $organization->id]);
        DB::table('organization_user')->insert([
            'organization_id' => $organization->id,
            'user_id' => $userId,
            'is_active' => true,
            'project_access_mode' => $mode->value,
        ]);

        $storedUser = User::query()->findOrFail($userId);
        $user = new class extends User
        {
            public function isOrganizationAdmin(?int $organizationId = null): bool
            {
                return false;
            }
        };
        $user->setRawAttributes($storedUser->getAttributes(), true);
        $user->exists = true;

        return [$organization, $user];
    }

    private function participantProject(Organization $organization): Project
    {
        $owner = $this->organization('Организация-владелец');
        $project = $this->project($owner->id);
        DB::table('project_organization')->insert([
            'project_id' => $project->id,
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);

        return $project;
    }

    private function organization(string $name): Organization
    {
        $id = DB::table('organizations')->insertGetId(['name' => $name]);

        return Organization::query()->findOrFail($id);
    }

    private function project(int $organizationId, bool $archived = false): Project
    {
        $id = DB::table('projects')->insertGetId([
            'organization_id' => $organizationId,
            'name' => 'Тестовый проект',
            'is_archived' => $archived,
        ]);

        return Project::query()->findOrFail($id);
    }
}
