<?php

declare(strict_types=1);

namespace Tests\Feature\ScheduleManagement;

use App\BusinessModules\Features\BudgetEstimates\Events\EstimateUpdated;
use App\BusinessModules\Features\ScheduleManagement\Listeners\SyncScheduleOnEstimateUpdate;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\User;
use App\Models\WorkType;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class EstimateScheduleAutoSyncTest extends TestCase
{
    public function test_estimate_update_auto_syncs_linked_schedule(): void
    {
        $this->assertTrue(Event::hasListeners(EstimateUpdated::class));

        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $unit = MeasurementUnit::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Квадратный метр',
            'short_name' => 'м2',
            'type' => 'work',
            'is_default' => false,
            'is_system' => false,
        ]);
        $workType = WorkType::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Монтаж',
            'code' => 'WORK',
            'measurement_unit_id' => $unit->id,
            'default_price' => 1000,
            'is_active' => true,
        ]);
        $estimate = Estimate::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'EST-AUTO-SYNC',
            'name' => 'Смета для графика',
            'type' => 'local',
            'status' => 'draft',
            'estimate_date' => '2026-05-21',
            'total_amount' => 1200,
            'total_amount_with_vat' => 1200,
            'vat_rate' => 0,
        ]);
        $section = EstimateSection::query()->create([
            'estimate_id' => $estimate->id,
            'section_number' => '1',
            'full_section_number' => '1',
            'name' => 'Раздел работ',
            'sort_order' => 1,
            'section_total_amount' => 1200,
        ]);
        $existingItem = EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'estimate_section_id' => $section->id,
            'position_number' => '1.1',
            'name' => 'Старая работа',
            'item_type' => 'work',
            'work_type_id' => $workType->id,
            'measurement_unit_id' => $unit->id,
            'quantity' => 2,
            'quantity_total' => 2,
            'unit_price' => 600,
            'total_amount' => 1200,
            'labor_hours' => 16,
        ]);
        $schedule = ProjectSchedule::query()->create([
            'project_id' => $project->id,
            'organization_id' => $organization->id,
            'created_by_user_id' => $user->id,
            'estimate_id' => $estimate->id,
            'sync_with_estimate' => true,
            'last_synced_at' => now()->subDay(),
            'sync_status' => 'synced',
            'name' => 'График по смете',
            'planned_start_date' => '2026-06-01',
            'planned_end_date' => '2026-06-10',
            'status' => 'draft',
            'calculation_settings' => [
                'working_days_per_week' => 5,
                'working_hours_per_day' => 8,
            ],
            'display_settings' => [],
            'critical_path_calculated' => true,
            'overall_progress_percent' => 0,
        ]);
        $sectionTask = ScheduleTask::query()->create([
            'schedule_id' => $schedule->id,
            'organization_id' => $organization->id,
            'created_by_user_id' => $user->id,
            'estimate_section_id' => $section->id,
            'name' => 'Раздел работ',
            'task_type' => 'summary',
            'planned_start_date' => '2026-06-01',
            'planned_end_date' => '2026-06-02',
            'planned_duration_days' => 2,
            'status' => 'not_started',
            'progress_percent' => 0,
            'priority' => 'normal',
            'constraint_type' => 'none',
            'level' => 0,
            'sort_order' => 1,
        ]);
        ScheduleTask::query()->create([
            'schedule_id' => $schedule->id,
            'organization_id' => $organization->id,
            'created_by_user_id' => $user->id,
            'parent_task_id' => $sectionTask->id,
            'estimate_item_id' => $existingItem->id,
            'estimate_section_id' => $section->id,
            'work_type_id' => $workType->id,
            'measurement_unit_id' => $unit->id,
            'name' => 'Старая работа',
            'task_type' => 'task',
            'planned_start_date' => '2026-06-01',
            'planned_end_date' => '2026-06-02',
            'planned_duration_days' => 2,
            'planned_work_hours' => 16,
            'quantity' => 2,
            'labor_hours_from_estimate' => 16,
            'resource_cost' => 1200,
            'estimated_cost' => 1200,
            'status' => 'not_started',
            'progress_percent' => 0,
            'priority' => 'normal',
            'constraint_type' => 'none',
            'level' => 1,
            'sort_order' => 2,
        ]);

        $existingItem->update([
            'name' => 'Обновленная работа',
            'quantity' => 3,
            'quantity_total' => 3,
            'total_amount' => 1800,
            'labor_hours' => 24,
        ]);
        $newItem = EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'estimate_section_id' => $section->id,
            'position_number' => '1.2',
            'name' => 'Новая работа',
            'item_type' => 'work',
            'work_type_id' => $workType->id,
            'measurement_unit_id' => $unit->id,
            'quantity' => 1,
            'quantity_total' => 1,
            'unit_price' => 900,
            'total_amount' => 900,
            'labor_hours' => 8,
        ]);
        $estimate->update([
            'total_amount' => 2700,
            'total_amount_with_vat' => 2700,
        ]);

        app(SyncScheduleOnEstimateUpdate::class)->handle(new EstimateUpdated($estimate->fresh()));

        $schedule->refresh();
        $updatedTask = ScheduleTask::query()->where('estimate_item_id', $existingItem->id)->firstOrFail();
        $createdTask = ScheduleTask::query()->where('estimate_item_id', $newItem->id)->firstOrFail();

        $this->assertSame('synced', $schedule->sync_status);
        $this->assertFalse((bool) $schedule->critical_path_calculated);
        $this->assertSame('Обновленная работа', $updatedTask->name);
        $this->assertEquals(3.0, (float) $updatedTask->quantity);
        $this->assertEquals(24.0, (float) $updatedTask->planned_work_hours);
        $this->assertSame('Новая работа', $createdTask->name);
        $this->assertSame($sectionTask->id, $createdTask->parent_task_id);
        $this->assertSame($user->id, $createdTask->created_by_user_id);
    }
}
