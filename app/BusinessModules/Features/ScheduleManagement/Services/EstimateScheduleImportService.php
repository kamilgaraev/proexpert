<?php

namespace App\BusinessModules\Features\ScheduleManagement\Services;

use App\Models\Estimate;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\EstimateSection;
use App\Models\EstimateItem;
use App\Enums\Schedule\ScheduleStatusEnum;
use App\Enums\Schedule\TaskTypeEnum;
use App\Enums\Schedule\TaskStatusEnum;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class EstimateScheduleImportService
{
    public function __construct(
        private readonly DurationCalculationService $durationCalculator
    ) {}

    /**
     * Создать график работ из сметы
     * 
     * @param Estimate $estimate Смета
     * @param array $options Опции импорта
     * @return ProjectSchedule Созданный график
     * 
     * @throws \DomainException Если смета не может быть импортирована
     */
    public function createScheduleFromEstimate(Estimate $estimate, array $options = []): ProjectSchedule
    {
        // Проверяем, что у сметы есть организация и проект
        if (!$estimate->organization_id) {
            throw new \DomainException('У сметы отсутствует организация');
        }

        // Параметры по умолчанию
        $name = $options['name'] ?? "График работ по смете: {$estimate->name}";
        $startDate = isset($options['start_date']) 
            ? Carbon::parse($options['start_date']) 
            : Carbon::now();
        $workersCount = $options['workers_count'] ?? 1;
        $hoursPerDay = $options['hours_per_day'] ?? 8;
        $includeWeekends = $options['include_weekends'] ?? false;
        $autoCalculateDates = $options['auto_calculate_dates'] ?? true;

        return DB::transaction(function () use (
            $estimate,
            $name,
            $startDate,
            $workersCount,
            $hoursPerDay,
            $includeWeekends,
            $autoCalculateDates
        ) {
            // Создаем график
            $schedule = ProjectSchedule::create([
                'organization_id' => $estimate->organization_id,
                'project_id' => $estimate->project_id,
                'estimate_id' => $estimate->id,
                'sync_with_estimate' => true,
                'last_synced_at' => now(),
                'sync_status' => 'synced',
                'name' => $name,
                'description' => "Импортировано из сметы #{$estimate->number}",
                'planned_start_date' => $startDate->toDateString(),
                'planned_end_date' => $startDate->copy()->addDays(30)->toDateString(), // Временно
                'status' => ScheduleStatusEnum::DRAFT,
                'total_estimated_cost' => $estimate->total_amount,
            ]);

            // Импортируем структуру сметы
            $this->importEstimateStructure($schedule, $estimate, [
                'workers_count' => $workersCount,
                'hours_per_day' => $hoursPerDay,
                'include_weekends' => $includeWeekends,
                'auto_calculate_dates' => $autoCalculateDates,
                'start_date' => $startDate,
            ]);

            // Пересчитываем даты окончания графика
            $this->recalculateScheduleDates($schedule);

            \Log::info('schedule.created_from_estimate', [
                'schedule_id' => $schedule->id,
                'estimate_id' => $estimate->id,
                'organization_id' => $schedule->organization_id,
            ]);

            return $schedule->fresh(['tasks', 'estimate']);
        });
    }

    /**
     * Импортировать структуру сметы в график
     * 
     * @param ProjectSchedule $schedule График
     * @param Estimate $estimate Смета
     * @param array $options Опции импорта
     * @return void
     */
    public function importEstimateStructure(
        ProjectSchedule $schedule,
        Estimate $estimate,
        array $options = []
    ): void {
        $sections = $estimate->sections()
            ->with(['items.workType', 'items.measurementUnit'])
            ->orderBy('sort_order')
            ->get();

        $currentDate = $options['start_date'] ?? Carbon::now();
        $sortOrder = 0;

        foreach ($sections as $section) {
            // Создаем группу задач для раздела
            $sectionTask = $this->createTaskFromSection(
                $schedule,
                $section,
                $currentDate,
                $sortOrder++,
                $options
            );

            // Импортируем позиции раздела как задачи
            if ($section->items && $section->items->count() > 0) {
                $taskStartDate = $currentDate->copy();
                
                foreach ($section->items as $item) {
                    $task = $this->createTaskFromItem(
                        $schedule,
                        $item,
                        $section,
                        $sectionTask,
                        $taskStartDate,
                        $sortOrder++,
                        $options
                    );

                    // Следующая задача начинается после текущей (последовательное выполнение)
                    if ($options['auto_calculate_dates'] ?? true) {
                        $taskStartDate = Carbon::parse($task->planned_end_date)->addDay();
                    }
                }

                // Обновляем даты группы задач на основе дочерних
                $this->updateSectionTaskDates($sectionTask);
                
                // Следующий раздел начинается после завершения текущего
                if ($options['auto_calculate_dates'] ?? true) {
                    $currentDate = Carbon::parse($sectionTask->planned_end_date)->addDay();
                }
            }
        }
    }

    /**
     * Создать задачу-группу из раздела сметы
     * 
     * @param ProjectSchedule $schedule График
     * @param EstimateSection $section Раздел сметы
     * @param Carbon $startDate Дата начала
     * @param int $sortOrder Порядок сортировки
     * @param array $options Опции
     * @return ScheduleTask Созданная задача
     */
    private function createTaskFromSection(
        ProjectSchedule $schedule,
        EstimateSection $section,
        Carbon $startDate,
        int $sortOrder,
        array $options
    ): ScheduleTask {
        return ScheduleTask::create([
            'schedule_id' => $schedule->id,
            'organization_id' => $schedule->organization_id,
            'estimate_section_id' => $section->id,
            'name' => $section->name,
            'description' => $section->description,
            'wbs_code' => $section->code,
            'task_type' => TaskTypeEnum::SUMMARY, // Группа задач
            'planned_start_date' => $startDate->toDateString(),
            'planned_end_date' => $startDate->copy()->addDay()->toDateString(), // Временно
            'planned_duration_days' => 1,
            'status' => TaskStatusEnum::NOT_STARTED,
            'progress_percent' => 0,
            'sort_order' => $sortOrder,
            'level' => 0,
        ]);
    }

    /**
     * Создать задачу из позиции сметы
     * 
     * @param ProjectSchedule $schedule График
     * @param EstimateItem $item Позиция сметы
     * @param EstimateSection $section Раздел сметы
     * @param ScheduleTask $parentTask Родительская задача
     * @param Carbon $startDate Дата начала
     * @param int $sortOrder Порядок сортировки
     * @param array $options Опции
     * @return ScheduleTask Созданная задача
     */
    private function createTaskFromItem(
        ProjectSchedule $schedule,
        EstimateItem $item,
        EstimateSection $section,
        ScheduleTask $parentTask,
        Carbon $startDate,
        int $sortOrder,
        array $options
    ): ScheduleTask {
        // Рассчитываем длительность на основе трудозатрат
        $durationDays = $this->calculateTaskDuration($item, $options);
        
        // Рассчитываем дату окончания
        $endDate = $this->durationCalculator->calculateEndDate(
            $startDate,
            $durationDays,
            [
                'include_weekends' => $options['include_weekends'] ?? false,
            ]
        );

        // Маппим данные из позиции сметы
        $taskData = $this->mapEstimateDataToTask($item, $section, $parentTask, $options);

        return ScheduleTask::create(array_merge($taskData, [
            'schedule_id' => $schedule->id,
            'organization_id' => $schedule->organization_id,
            'parent_task_id' => $parentTask->id,
            'estimate_item_id' => $item->id,
            'estimate_section_id' => $section->id,
            'planned_start_date' => $startDate->toDateString(),
            'planned_end_date' => $endDate->toDateString(),
            'planned_duration_days' => $durationDays,
            'sort_order' => $sortOrder,
            'level' => 1,
        ]));
    }

    /**
     * Рассчитать длительность задачи на основе позиции сметы
     * 
     * @param EstimateItem $item Позиция сметы
     * @param array $options Опции расчета
     * @return int Длительность в днях
     */
    public function calculateTaskDuration(EstimateItem $item, array $options = []): int
    {
        $workersCount = $options['workers_count'] ?? 1;
        $hoursPerDay = $options['hours_per_day'] ?? 8;

        // Если есть трудозатраты в смете, используем их
        if ($item->labor_hours && $item->labor_hours > 0) {
            return $this->durationCalculator->calculateFromLaborHours(
                $item->labor_hours,
                $workersCount,
                $hoursPerDay
            );
        }

        // Иначе используем дефолтную длительность
        return 1;
    }

    /**
     * Маппинг данных из позиции сметы в задачу графика
     * 
     * @param EstimateItem $item Позиция сметы
     * @param EstimateSection $section Раздел сметы
     * @param ScheduleTask $parentTask Родительская задача
     * @param array $options Опции
     * @return array Данные для создания задачи
     */
    public function mapEstimateDataToTask(
        EstimateItem $item,
        EstimateSection $section,
        ScheduleTask $parentTask,
        array $options = []
    ): array {
        return [
            'name' => $item->name,
            'description' => $item->description ?? $item->justification,
            'task_type' => TaskTypeEnum::TASK,
            'status' => TaskStatusEnum::NOT_STARTED,
            'progress_percent' => 0,
            
            // Данные из сметы
            'work_type_id' => $item->work_type_id,
            'quantity' => $item->quantity_total ?? $item->quantity,
            'measurement_unit_id' => $item->measurement_unit_id,
            'labor_hours_from_estimate' => $item->labor_hours,
            'planned_work_hours' => $item->labor_hours ?? 0,
            'resource_cost' => $item->total_amount,
            'estimated_cost' => $item->total_amount,
            
            // Метаданные
            'custom_fields' => [
                'imported_from_estimate' => true,
                'estimate_item_code' => $item->normative_rate_code,
                'materials_cost' => $item->materials_cost,
                'machinery_cost' => $item->machinery_cost,
                'labor_cost' => $item->labor_cost,
            ],
        ];
    }

    /**
     * Обновить даты задачи-группы на основе дочерних задач
     * 
     * @param ScheduleTask $sectionTask Задача-группа
     * @return void
     */
    private function updateSectionTaskDates(ScheduleTask $sectionTask): void
    {
        $childTasks = $sectionTask->childTasks;

        if ($childTasks->isEmpty()) {
            return;
        }

        $minStartDate = $childTasks->min('planned_start_date');
        $maxEndDate = $childTasks->max('planned_end_date');

        if ($minStartDate && $maxEndDate) {
            $startDate = Carbon::parse($minStartDate);
            $endDate = Carbon::parse($maxEndDate);
            
            $sectionTask->update([
                'planned_start_date' => $startDate->toDateString(),
                'planned_end_date' => $endDate->toDateString(),
                'planned_duration_days' => $startDate->diffInDays($endDate) + 1,
            ]);
        }
    }

    /**
     * Пересчитать даты графика на основе задач
     * 
     * @param ProjectSchedule $schedule График
     * @return void
     */
    private function recalculateScheduleDates(ProjectSchedule $schedule): void
    {
        $tasks = $schedule->tasks()->get();

        if ($tasks->isEmpty()) {
            return;
        }

        $minStartDate = $tasks->min('planned_start_date');
        $maxEndDate = $tasks->max('planned_end_date');

        if ($minStartDate && $maxEndDate) {
            $schedule->update([
                'planned_start_date' => $minStartDate,
                'planned_end_date' => $maxEndDate,
            ]);
        }
    }
}

