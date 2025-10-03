<?php

namespace App\Console\Commands;

use App\Models\CustomReportSchedule;
use Illuminate\Console\Command;

class ListScheduledReportsCommand extends Command
{
    protected $signature = 'custom-reports:list-schedules
                          {--active : Показать только активные расписания}';

    protected $description = 'Показать список запланированных отчетов';

    public function handle(): int
    {
        $query = CustomReportSchedule::with(['customReport', 'organization']);

        if ($this->option('active')) {
            $query->active();
        }

        $schedules = $query->orderBy('next_run_at')->get();

        if ($schedules->isEmpty()) {
            $this->info('Запланированных отчетов не найдено');
            return Command::SUCCESS;
        }

        $this->info('Запланированные отчеты:');
        $this->newLine();

        $tableData = [];
        foreach ($schedules as $schedule) {
            $tableData[] = [
                'ID' => $schedule->id,
                'Отчет' => $schedule->customReport->name ?? 'N/A',
                'Организация' => $schedule->organization->name ?? 'N/A',
                'Тип' => $schedule->schedule_type,
                'Активен' => $schedule->is_active ? 'Да' : 'Нет',
                'След. запуск' => $schedule->next_run_at?->format('d.m.Y H:i') ?? 'N/A',
                'Посл. запуск' => $schedule->last_run_at?->format('d.m.Y H:i') ?? 'Никогда',
            ];
        }

        $this->table(
            ['ID', 'Отчет', 'Организация', 'Тип', 'Активен', 'След. запуск', 'Посл. запуск'],
            $tableData
        );

        return Command::SUCCESS;
    }
}

