<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProjectWorkTypesTest extends TestCase
{
    protected function refreshTestDatabase(): void
    {
    }

    public function test_project_work_types_expose_plan_and_completed_quantities(): void
    {
        $this->withoutMiddleware();
        $this->createSchema();

        $organizationId = DB::table('organizations')->insertGetId([
            'name' => 'Test organization',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userId = DB::table('users')->insertGetId([
            'name' => 'Engineer',
            'email' => 'engineer@example.test',
            'password' => 'secret',
            'current_organization_id' => $organizationId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $projectId = DB::table('projects')->insertGetId([
            'organization_id' => $organizationId,
            'name' => 'Test project',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $unitId = DB::table('measurement_units')->insertGetId([
            'organization_id' => $organizationId,
            'name' => 'Cubic meter',
            'short_name' => 'm3',
            'type' => 'work',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $workTypeId = DB::table('work_types')->insertGetId([
            'organization_id' => $organizationId,
            'name' => 'Foundation concreting',
            'measurement_unit_id' => $unitId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $scheduleId = DB::table('project_schedules')->insertGetId([
            'project_id' => $projectId,
            'organization_id' => $organizationId,
            'created_by_user_id' => $userId,
            'name' => 'Main schedule',
            'planned_start_date' => '2026-04-01',
            'planned_end_date' => '2026-04-30',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('schedule_tasks')->insert([
            'schedule_id' => $scheduleId,
            'organization_id' => $organizationId,
            'created_by_user_id' => $userId,
            'work_type_id' => $workTypeId,
            'name' => 'Foundation',
            'task_type' => 'task',
            'planned_start_date' => '2026-04-01',
            'planned_end_date' => '2026-04-30',
            'planned_duration_days' => 30,
            'planned_work_hours' => 0,
            'quantity' => 50,
            'measurement_unit_id' => $unitId,
            'status' => 'in_progress',
            'priority' => 'normal',
            'progress_percent' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('completed_works')->insert([
            [
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'work_type_id' => $workTypeId,
                'user_id' => $userId,
                'quantity' => 20,
                'completed_quantity' => null,
                'price' => 15000,
                'total_amount' => 300000,
                'completion_date' => '2026-04-26',
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'work_type_id' => $workTypeId,
                'user_id' => $userId,
                'quantity' => 100,
                'completed_quantity' => 5,
                'price' => 1000,
                'total_amount' => 5000,
                'completion_date' => '2026-04-27',
                'status' => 'confirmed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->getJson("/api/v1/admin/projects/{$projectId}/work-types?per_page=10");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.data.0.work_type_id', $workTypeId)
            ->assertJsonPath('data.data.0.planned_quantity', 50)
            ->assertJsonPath('data.data.0.completed_quantity', 25)
            ->assertJsonPath('data.data.0.completion_percentage', 50)
            ->assertJsonPath('data.data.0.works_count', 2);
    }

    public function test_project_work_types_include_untyped_estimate_items(): void
    {
        $this->withoutMiddleware();
        $this->createSchema();

        $organizationId = DB::table('organizations')->insertGetId([
            'name' => 'Test organization',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userId = DB::table('users')->insertGetId([
            'name' => 'Engineer',
            'email' => 'engineer-untyped@example.test',
            'password' => 'secret',
            'current_organization_id' => $organizationId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $projectId = DB::table('projects')->insertGetId([
            'organization_id' => $organizationId,
            'name' => 'Project with estimate work',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $unitId = DB::table('measurement_units')->insertGetId([
            'organization_id' => $organizationId,
            'name' => 'Cubic meter',
            'short_name' => 'm3',
            'type' => 'work',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $estimateId = DB::table('estimates')->insertGetId([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'number' => 'EST-1',
            'name' => 'Main estimate',
            'type' => 'local',
            'status' => 'approved',
            'estimate_date' => '2026-04-01',
            'calculation_method' => 'resource',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $estimateItemId = DB::table('estimate_items')->insertGetId([
            'estimate_id' => $estimateId,
            'position_number' => '1',
            'name' => 'Estimate concreting',
            'work_type_id' => null,
            'measurement_unit_id' => $unitId,
            'quantity' => 115,
            'quantity_total' => null,
            'unit_price' => 1000,
            'direct_costs' => 115000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $scheduleId = DB::table('project_schedules')->insertGetId([
            'project_id' => $projectId,
            'organization_id' => $organizationId,
            'created_by_user_id' => $userId,
            'name' => 'Main schedule',
            'planned_start_date' => '2026-04-01',
            'planned_end_date' => '2026-04-30',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('schedule_tasks')->insert([
            'schedule_id' => $scheduleId,
            'organization_id' => $organizationId,
            'created_by_user_id' => $userId,
            'estimate_item_id' => $estimateItemId,
            'work_type_id' => null,
            'name' => 'Estimate concreting',
            'task_type' => 'task',
            'planned_start_date' => '2026-04-01',
            'planned_end_date' => '2026-04-30',
            'planned_duration_days' => 30,
            'planned_work_hours' => 0,
            'quantity' => 115,
            'measurement_unit_id' => $unitId,
            'status' => 'in_progress',
            'priority' => 'normal',
            'progress_percent' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('completed_works')->insert([
            [
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'estimate_item_id' => $estimateItemId,
                'work_type_id' => null,
                'user_id' => $userId,
                'quantity' => 15,
                'completed_quantity' => 15,
                'price' => 1000,
                'total_amount' => 15000,
                'completion_date' => '2026-04-28',
                'status' => 'confirmed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'estimate_item_id' => $estimateItemId,
                'work_type_id' => null,
                'user_id' => $userId,
                'quantity' => 30,
                'completed_quantity' => 30,
                'price' => 1000,
                'total_amount' => 30000,
                'completion_date' => '2026-04-29',
                'status' => 'confirmed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->getJson("/api/v1/admin/projects/{$projectId}/work-types?per_page=10");

        $response->assertOk()->assertJsonPath('success', true);

        $rows = collect($response->json('data.data'));
        $row = $rows->firstWhere('work_type_name', 'Estimate concreting');

        $this->assertNotNull($row);
        $this->assertLessThan(0, $row['work_type_id']);
        $this->assertSame(115.0, (float) $row['planned_quantity']);
        $this->assertSame(45.0, (float) $row['completed_quantity']);
        $this->assertSame(39.13, (float) $row['completion_percentage']);
        $this->assertSame(2, (int) $row['works_count']);
        $this->assertSame('m3', $row['unit']);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('completed_works');
        Schema::dropIfExists('schedule_tasks');
        Schema::dropIfExists('project_schedules');
        Schema::dropIfExists('estimate_items');
        Schema::dropIfExists('estimates');
        Schema::dropIfExists('work_types');
        Schema::dropIfExists('measurement_units');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('users');
        Schema::dropIfExists('organizations');

        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->unsignedBigInteger('current_organization_id')->nullable();
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('measurement_units', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->string('short_name');
            $table->string('type')->default('work');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('work_types', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->unsignedBigInteger('measurement_unit_id')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('project_schedules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('created_by_user_id');
            $table->string('name');
            $table->date('planned_start_date');
            $table->date('planned_end_date');
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('estimates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('number');
            $table->string('name');
            $table->string('type')->default('local');
            $table->string('status')->default('draft');
            $table->date('estimate_date');
            $table->string('calculation_method')->default('resource');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('estimate_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('estimate_id');
            $table->string('position_number');
            $table->string('name');
            $table->unsignedBigInteger('work_type_id')->nullable();
            $table->unsignedBigInteger('measurement_unit_id')->nullable();
            $table->decimal('quantity', 15, 8)->default(0);
            $table->decimal('quantity_total', 15, 8)->nullable();
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('direct_costs', 15, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('schedule_tasks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('schedule_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('estimate_item_id')->nullable();
            $table->unsignedBigInteger('work_type_id')->nullable();
            $table->string('name');
            $table->string('task_type')->default('task');
            $table->date('planned_start_date');
            $table->date('planned_end_date');
            $table->integer('planned_duration_days')->default(0);
            $table->decimal('planned_work_hours', 8, 2)->default(0);
            $table->decimal('quantity', 10, 4)->nullable();
            $table->unsignedBigInteger('measurement_unit_id')->nullable();
            $table->string('status')->default('not_started');
            $table->string('priority')->default('normal');
            $table->decimal('progress_percent', 5, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('completed_works', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('schedule_task_id')->nullable();
            $table->unsignedBigInteger('estimate_item_id')->nullable();
            $table->unsignedBigInteger('work_type_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->decimal('quantity', 15, 3);
            $table->decimal('completed_quantity', 12, 4)->nullable();
            $table->decimal('price', 15, 2)->nullable();
            $table->decimal('total_amount', 15, 2)->nullable();
            $table->date('completion_date');
            $table->string('status')->default('confirmed');
            $table->timestamps();
            $table->softDeletes();
        });
    }
}
