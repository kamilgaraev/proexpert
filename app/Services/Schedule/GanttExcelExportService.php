<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\TaskDependency;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class GanttExcelExportService
{
    private const BRAND_DARK  = '1E3A5F';
    private const BRAND_LIGHT = 'E8F4FD';
    private const BRAND_ACCENT = '2563EB';

    private const CRITICAL_BG = 'FFE4E4';
    private const CRITICAL_FG = 'CC0000';
    private const SUMMARY_BG  = 'E8F0FE';
    private const MILESTONE_BG = 'FEF3C7';

    private const STATUS_COLORS = [
        'not_started' => 'E5E7EB',
        'in_progress' => 'BFDBFE',
        'completed'   => 'D1FAE5',
        'on_hold'     => 'FEF9C3',
        'cancelled'   => 'F3F4F6',
    ];

    private const STATUS_LABELS = [
        'not_started' => 'Не начата',
        'in_progress' => 'В работе',
        'completed'   => 'Завершена',
        'on_hold'     => 'На паузе',
        'cancelled'   => 'Отменена',
    ];

    private const DEPENDENCY_TYPES = [
        'FS' => 'Финиш → Старт',
        'SS' => 'Старт → Старт',
        'FF' => 'Финиш → Финиш',
        'SF' => 'Старт → Финиш',
        'finish_to_start'  => 'Финиш → Старт',
        'start_to_start'   => 'Старт → Старт',
        'finish_to_finish'  => 'Финиш → Финиш',
        'start_to_finish'  => 'Старт → Финиш',
    ];

    public function export(ProjectSchedule $schedule): string
    {
        $schedule->load([
            'tasks' => fn($q) => $q->orderBy('sort_order')->orderBy('level'),
            'dependencies.predecessorTask',
            'dependencies.successorTask',
            'project',
        ]);

        $tasks = $schedule->tasks ?? collect();
        $dependencies = $schedule->dependencies ?? collect();

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setTitle($schedule->name)
            ->setSubject('График работ ProHelper')
            ->setCreator('ProHelper')
            ->setCompany('ProHelper')
            ->setDescription('Экспорт графика работ из системы ProHelper');

        $this->buildCoverSheet($spreadsheet->getActiveSheet(), $schedule, $tasks, $dependencies);
        $this->buildScheduleSheet($spreadsheet->createSheet(), $schedule, $tasks);
        $this->buildDependenciesSheet($spreadsheet->createSheet(), $dependencies, $tasks);
        $this->buildCriticalPathSheet($spreadsheet->createSheet(), $tasks);

        $spreadsheet->setActiveSheetIndex(0);

        $tempPath = tempnam(sys_get_temp_dir(), 'gantt_export_') . '.xlsx';

        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        return $tempPath;
    }

    private function buildCoverSheet(Worksheet $sheet, ProjectSchedule $schedule, Collection $tasks, Collection $dependencies): void
    {
        $sheet->setTitle('Обложка');

        $sheet->getColumnDimension('A')->setWidth(3);
        $sheet->getColumnDimension('B')->setWidth(35);
        $sheet->getColumnDimension('C')->setWidth(45);

        $sheet->mergeCells('A1:C3');
        $sheet->setCellValue('A1', '');
        $sheet->getStyle('A1:C3')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['argb' => 'FF' . self::BRAND_DARK]],
        ]);

        $sheet->mergeCells('A4:C5');
        $sheet->setCellValue('A4', '  ' . strtoupper('ProHelper — Управление проектами'));
        $sheet->getStyle('A4:C5')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['argb' => 'FF' . self::BRAND_DARK]],
            'font' => ['bold' => true, 'size' => 18, 'color' => ['argb' => 'FFFFFFFF']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $sheet->mergeCells('A6:C7');
        $sheet->setCellValue('A6', '  График работ');
        $sheet->getStyle('A6:C7')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['argb' => 'FF' . self::BRAND_ACCENT]],
            'font' => ['bold' => false, 'size' => 12, 'color' => ['argb' => 'FFFFFFFF']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $sheet->getRowDimension(1)->setRowHeight(10);
        $sheet->getRowDimension(2)->setRowHeight(10);
        $sheet->getRowDimension(3)->setRowHeight(10);
        $sheet->getRowDimension(4)->setRowHeight(30);
        $sheet->getRowDimension(5)->setRowHeight(10);
        $sheet->getRowDimension(6)->setRowHeight(25);
        $sheet->getRowDimension(7)->setRowHeight(10);

        $row = 9;
        $infoRows = [
            ['Название графика', $schedule->name],
            ['Проект', $schedule->project?->name ?? '—'],
            ['Статус', $this->getScheduleStatusLabel($schedule->status)],
            ['Дата начала', $schedule->start_date ? Carbon::parse($schedule->start_date)->format('d.m.Y') : '—'],
            ['Дата окончания', $schedule->end_date ? Carbon::parse($schedule->end_date)->format('d.m.Y') : '—'],
            ['Дата экспорта', Carbon::now()->format('d.m.Y H:i')],
            ['', ''],
            ['СТАТИСТИКА', ''],
            ['Всего задач', $tasks->count()],
            ['Суммарных задач', $tasks->where('task_type', 'summary')->count()],
            ['Вех', $tasks->where('task_type', 'milestone')->count()],
            ['На критическом пути', $tasks->where('is_critical', true)->count()],
            ['Всего зависимостей', $dependencies->count()],
            ['Общий прогресс', round($tasks->avg('progress_percent') ?? 0, 1) . '%'],
            ['Плановая стоимость', number_format((float)($tasks->sum('estimated_cost') ?? 0), 2, '.', ' ') . ' ₽'],
        ];

        foreach ($infoRows as [$label, $value]) {
            if ($label === 'СТАТИСТИКА') {
                $sheet->setCellValue("B{$row}", $label);
                $sheet->getStyle("B{$row}:C{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 11, 'color' => ['argb' => 'FF' . self::BRAND_DARK]],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['argb' => 'FF' . self::BRAND_LIGHT]],
                ]);
                $row++;
                continue;
            }
            if ($label === '') {
                $row++;
                continue;
            }

            $sheet->setCellValue("B{$row}", $label);
            $sheet->setCellValue("C{$row}", $value);
            $sheet->getStyle("B{$row}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => 'FF374151']],
            ]);
            $sheet->getStyle("C{$row}")->applyFromArray([
                'font' => ['color' => ['argb' => 'FF111827']],
            ]);
            $row++;
        }

        $row += 2;
        $this->buildLegend($sheet, $row);
    }

    private function buildLegend(Worksheet $sheet, int $startRow): void
    {
        $sheet->setCellValue("B{$startRow}", 'ЛЕГЕНДА ЦВЕТОВ');
        $sheet->getStyle("B{$startRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['argb' => 'FF' . self::BRAND_DARK]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['argb' => 'FF' . self::BRAND_LIGHT]],
        ]);
        $startRow++;

        $legend = [
            [self::CRITICAL_BG, 'Задача на критическом пути — нельзя задерживать'],
            [self::SUMMARY_BG,  'Суммарная задача (группа)'],
            [self::MILESTONE_BG, 'Веха (контрольная точка)'],
            ['D1FAE5', 'Завершена'],
            ['BFDBFE', 'В работе'],
            ['E5E7EB', 'Не начата'],
        ];

        foreach ($legend as [$color, $label]) {
            $sheet->setCellValue("B{$startRow}", '');
            $sheet->getStyle("B{$startRow}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['argb' => 'FF' . $color]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFD1D5DB']]],
            ]);
            $sheet->setCellValue("C{$startRow}", $label);
            $startRow++;
        }
    }

    private function buildScheduleSheet(Worksheet $sheet, ProjectSchedule $schedule, Collection $tasks): void
    {
        $sheet->setTitle('График');

        $headers = ['WBS', 'Название задачи', 'Тип', 'Нач. план', 'Оконч. план', 'Дней', 'Прогресс %', 'Стоимость (₽)', 'Факт (₽)', 'Статус', 'Критич.', 'Резерв (дн.)'];
        $cols    = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'];
        $widths  = [12, 45, 14, 14, 14, 8, 12, 18, 18, 14, 10, 14];

        foreach ($cols as $i => $col) {
            $sheet->getColumnDimension($col)->setWidth($widths[$i]);
        }

        $headerRow = 1;
        $this->writeSectionHeader($sheet, "A{$headerRow}:L{$headerRow}", $schedule->name . ' — Задачи');

        $headerRow = 2;
        foreach ($headers as $i => $header) {
            $sheet->setCellValue($cols[$i] . $headerRow, $header);
        }
        $this->applyTableHeader($sheet, "A{$headerRow}:L{$headerRow}");
        $sheet->getRowDimension($headerRow)->setRowHeight(22);

        $dataRow = 3;
        foreach ($tasks as $task) {
            $indent = str_repeat('  ', max(0, ($task->level ?? 0) - 1));
            $isCritical = (bool)($task->is_critical ?? false);
            $type = $task->task_type ?? 'task';

            $sheet->setCellValue("A{$dataRow}", $task->wbs_code ?? '');
            $sheet->setCellValue("B{$dataRow}", $indent . ($task->name ?? ''));
            $sheet->setCellValue("C{$dataRow}", $this->getTaskTypeLabel($type));
            $sheet->setCellValue("D{$dataRow}", $task->planned_start_date ? Carbon::parse($task->planned_start_date)->format('d.m.Y') : '');
            $sheet->setCellValue("E{$dataRow}", $task->planned_end_date ? Carbon::parse($task->planned_end_date)->format('d.m.Y') : '');
            $sheet->setCellValue("F{$dataRow}", $task->planned_duration_days ?? '');
            $sheet->setCellValue("G{$dataRow}", $task->progress_percent ?? 0);
            $sheet->setCellValue("H{$dataRow}", $task->estimated_cost ?? 0);
            $sheet->setCellValue("I{$dataRow}", $task->actual_cost ?? 0);
            $sheet->setCellValue("J{$dataRow}", self::STATUS_LABELS[$task->status ?? 'not_started'] ?? ($task->status ?? ''));
            $sheet->setCellValue("K{$dataRow}", $isCritical ? '🔴 Да' : 'Нет');
            $sheet->setCellValue("L{$dataRow}", $task->total_float_days ?? '');

            $bgColor = $this->getTaskRowBgColor($task, $isCritical);
            $sheet->getStyle("A{$dataRow}:L{$dataRow}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['argb' => 'FF' . $bgColor]],
                'font' => [
                    'bold'  => ($type === 'summary' || $type === 'container' || $isCritical),
                    'color' => ['argb' => $isCritical ? 'FF' . self::CRITICAL_FG : 'FF111827'],
                ],
                'borders' => [
                    'bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFE5E7EB']],
                ],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);

            $sheet->getStyle("G{$dataRow}")->getNumberFormat()->setFormatCode('0"%"');
            $sheet->getStyle("H{$dataRow}:I{$dataRow}")->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle("F{$dataRow}:G{$dataRow}:L{$dataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            $sheet->getRowDimension($dataRow)->setRowHeight(20);
            $dataRow++;
        }

        $sheet->setAutoFilter("A2:L2");
        $sheet->freezePane('A3');
    }

    private function buildDependenciesSheet(Worksheet $sheet, Collection $dependencies, Collection $tasks): void
    {
        $sheet->setTitle('Зависимости');

        $tasksMap = $tasks->keyBy('id');

        $headers = ['№', 'Предшественник (ID)', 'Название предшественника', 'Тип', 'Преемник (ID)', 'Название преемника', 'Тип связи', 'Лаг (дн.)', 'Обязательная', 'Критическая'];
        $cols    = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];
        $widths  = [6, 18, 40, 14, 18, 40, 18, 12, 14, 12];

        foreach ($cols as $i => $col) {
            $sheet->getColumnDimension($col)->setWidth($widths[$i]);
        }

        $this->writeSectionHeader($sheet, 'A1:J1', 'Зависимости между задачами');

        $headerRow = 2;
        foreach ($headers as $i => $header) {
            $sheet->setCellValue($cols[$i] . $headerRow, $header);
        }
        $this->applyTableHeader($sheet, "A{$headerRow}:J{$headerRow}");

        $dataRow = 3;
        $num = 1;
        foreach ($dependencies as $dep) {
            $predTask = $tasksMap->get($dep->predecessor_task_id);
            $succTask = $tasksMap->get($dep->successor_task_id);
            $isCritical = (bool)($dep->is_critical ?? false);

            $depTypeLabel = self::DEPENDENCY_TYPES[$dep->dependency_type ?? 'FS'] ?? ($dep->dependency_type ?? 'FS');

            $sheet->setCellValue("A{$dataRow}", $num++);
            $sheet->setCellValue("B{$dataRow}", $dep->predecessor_task_id);
            $sheet->setCellValue("C{$dataRow}", $predTask?->name ?? '—');
            $sheet->setCellValue("D{$dataRow}", $predTask ? $this->getTaskTypeLabel($predTask->task_type ?? 'task') : '');
            $sheet->setCellValue("E{$dataRow}", $dep->successor_task_id);
            $sheet->setCellValue("F{$dataRow}", $succTask?->name ?? '—');
            $sheet->setCellValue("G{$dataRow}", $depTypeLabel);
            $sheet->setCellValue("H{$dataRow}", $dep->lag_days ?? 0);
            $sheet->setCellValue("I{$dataRow}", ($dep->is_mandatory ?? false) ? 'Да' : 'Нет');
            $sheet->setCellValue("J{$dataRow}", $isCritical ? '🔴 Да' : 'Нет');

            $bgColor = $isCritical ? self::CRITICAL_BG : 'FFFFFF';
            $sheet->getStyle("A{$dataRow}:J{$dataRow}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['argb' => 'FF' . $bgColor]],
                'font' => [
                    'bold'  => $isCritical,
                    'color' => ['argb' => $isCritical ? 'FF' . self::CRITICAL_FG : 'FF111827'],
                ],
                'borders' => [
                    'bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFE5E7EB']],
                ],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);

            $sheet->getRowDimension($dataRow)->setRowHeight(19);
            $dataRow++;
        }

        if ($dependencies->isEmpty()) {
            $sheet->setCellValue("A3", 'Зависимости отсутствуют');
            $sheet->mergeCells("A3:J3");
            $sheet->getStyle("A3")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $sheet->setAutoFilter("A2:J2");
        $sheet->freezePane('A3');
    }

    private function buildCriticalPathSheet(Worksheet $sheet, Collection $tasks): void
    {
        $sheet->setTitle('Критический путь');

        $criticalTasks = $tasks->filter(fn($t) => (bool)($t->is_critical ?? false))->values();

        $headers = ['WBS', 'Задача', 'Нач. план', 'Оконч. план', 'Ранний старт', 'Ранний финиш', 'Поздний старт', 'Поздний финиш', 'Общий резерв', 'Свободный резерв', 'Прогресс %'];
        $cols    = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K'];
        $widths  = [12, 42, 14, 14, 14, 14, 14, 14, 14, 16, 12];

        foreach ($cols as $i => $col) {
            $sheet->getColumnDimension($col)->setWidth($widths[$i]);
        }

        $this->writeSectionHeader($sheet, 'A1:K1', '🔴 Критический путь — задачи без временного резерва');

        $headerRow = 2;
        foreach ($headers as $i => $header) {
            $sheet->setCellValue($cols[$i] . $headerRow, $header);
        }
        $this->applyTableHeader($sheet, "A{$headerRow}:K{$headerRow}", self::CRITICAL_FG);

        $dataRow = 3;
        foreach ($criticalTasks as $task) {
            $sheet->setCellValue("A{$dataRow}", $task->wbs_code ?? '');
            $sheet->setCellValue("B{$dataRow}", $task->name ?? '');
            $sheet->setCellValue("C{$dataRow}", $task->planned_start_date ? Carbon::parse($task->planned_start_date)->format('d.m.Y') : '');
            $sheet->setCellValue("D{$dataRow}", $task->planned_end_date ? Carbon::parse($task->planned_end_date)->format('d.m.Y') : '');
            $sheet->setCellValue("E{$dataRow}", $task->early_start_date ? Carbon::parse($task->early_start_date)->format('d.m.Y') : '');
            $sheet->setCellValue("F{$dataRow}", $task->early_finish_date ? Carbon::parse($task->early_finish_date)->format('d.m.Y') : '');
            $sheet->setCellValue("G{$dataRow}", $task->late_start_date ? Carbon::parse($task->late_start_date)->format('d.m.Y') : '');
            $sheet->setCellValue("H{$dataRow}", $task->late_finish_date ? Carbon::parse($task->late_finish_date)->format('d.m.Y') : '');
            $sheet->setCellValue("I{$dataRow}", $task->total_float_days ?? 0);
            $sheet->setCellValue("J{$dataRow}", $task->free_float_days ?? 0);
            $sheet->setCellValue("K{$dataRow}", $task->progress_percent ?? 0);

            $sheet->getStyle("A{$dataRow}:K{$dataRow}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['argb' => 'FFFFECE5']],
                'font' => ['color' => ['argb' => 'FF' . self::CRITICAL_FG]],
                'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFFCA5A5']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);

            $sheet->getStyle("K{$dataRow}")->getNumberFormat()->setFormatCode('0"%"');
            $sheet->getRowDimension($dataRow)->setRowHeight(20);
            $dataRow++;
        }

        if ($criticalTasks->isEmpty()) {
            $sheet->setCellValue('A3', 'Критический путь не рассчитан. Используйте кнопку «Критический путь» в системе.');
            $sheet->mergeCells('A3:K3');
            $sheet->getStyle('A3')->applyFromArray([
                'font' => ['italic' => true, 'color' => ['argb' => 'FF6B7280']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
        } else {
            $dataRow++;
            $sheet->setCellValue("A{$dataRow}", 'Итого задач на крит. пути:');
            $sheet->setCellValue("B{$dataRow}", $criticalTasks->count());
            $sheet->getStyle("A{$dataRow}:B{$dataRow}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['argb' => 'FF' . self::CRITICAL_BG]],
            ]);
        }

        $sheet->setAutoFilter("A2:K2");
        $sheet->freezePane('A3');
    }

    private function writeSectionHeader(Worksheet $sheet, string $range, string $title): void
    {
        [$start] = explode(':', $range);
        $sheet->mergeCells($range);
        $sheet->setCellValue($start, $title);
        $sheet->getStyle($range)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['argb' => 'FF' . self::BRAND_DARK]],
            'font' => ['bold' => true, 'size' => 12, 'color' => ['argb' => 'FFFFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);
    }

    private function applyTableHeader(Worksheet $sheet, string $range, string $bgColorHex = ''): void
    {
        $bg = $bgColorHex ?: self::BRAND_DARK;
        $sheet->getStyle($range)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['argb' => 'FF' . $bg]],
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 10],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => [
                'bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FFFFFFFF']],
            ],
        ]);
    }

    private function getTaskRowBgColor(ScheduleTask $task, bool $isCritical): string
    {
        if ($isCritical) {
            return self::CRITICAL_BG;
        }

        $type = $task->task_type ?? 'task';

        if ($type === 'summary' || $type === 'container') {
            return self::SUMMARY_BG;
        }

        if ($type === 'milestone') {
            return self::MILESTONE_BG;
        }

        $status = $task->status ?? 'not_started';
        return self::STATUS_COLORS[$status] ?? 'FFFFFF';
    }

    private function getTaskTypeLabel(string $type): string
    {
        return match ($type) {
            'summary', 'container' => 'Суммарная',
            'milestone'            => 'Веха',
            default                => 'Работа',
        };
    }

    private function getScheduleStatusLabel(?string $status): string
    {
        return match ($status) {
            'draft'       => 'Черновик',
            'active'      => 'Активный',
            'on_hold'     => 'На паузе',
            'completed'   => 'Завершён',
            'cancelled'   => 'Отменён',
            default       => $status ?? '—',
        };
    }
}
