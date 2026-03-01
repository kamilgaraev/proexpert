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
            'tasks.intervals',
            'dependencies.predecessorTask',
            'dependencies.successorTask',
            'project',
        ]);

        $allTasks = $schedule->tasks ?? collect();
        $tasks = $this->flattenTasksHierarchically($allTasks);
        $dependencies = $schedule->dependencies ?? collect();

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setTitle($schedule->name)
            ->setSubject('График работ ProHelper')
            ->setCreator('ProHelper')
            ->setCompany('ProHelper')
            ->setDescription('Экспорт графика работ из системы ProHelper');

        $this->buildGanttSheet($spreadsheet->getActiveSheet(), $schedule, $tasks, 'month');
        $this->buildGanttSheet($spreadsheet->createSheet(), $schedule, $tasks, 'week');
        $this->buildGanttSheet($spreadsheet->createSheet(), $schedule, $tasks, 'day');
        $this->buildScheduleSheet($spreadsheet->createSheet(), $schedule, $tasks);
        $this->buildDependenciesSheet($spreadsheet->createSheet(), $dependencies, $tasks);
        $this->buildCriticalPathSheet($spreadsheet->createSheet(), $tasks);

        $spreadsheet->setActiveSheetIndex(0);

        $tempPath = tempnam(sys_get_temp_dir(), 'gantt_export_') . '.xlsx';

        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        return $tempPath;
    }

    private function buildGanttSheet(Worksheet $sheet, ProjectSchedule $schedule, Collection $tasks, string $scale = 'month'): void
    {
        $scaleLabels = ['month' => 'Месяц', 'week' => 'Неделя', 'day' => 'День'];
        $sheet->setTitle('Гант — ' . ($scaleLabels[$scale] ?? $scale));

        $minDate = null;
        $maxDate = null;
        foreach ($tasks as $task) {
            if ($task->planned_start_date) {
                $d = Carbon::parse($task->planned_start_date);
                if (!$minDate || $d->lt($minDate)) $minDate = $d;
            }
            if ($task->planned_end_date) {
                $d = Carbon::parse($task->planned_end_date);
                if (!$maxDate || $d->gt($maxDate)) $maxDate = $d;
            }
        }

        if (!$minDate || !$maxDate || $tasks->isEmpty()) {
            $this->buildMiniCover($sheet, $schedule, $scale, 'A1');
            $sheet->setCellValue('A5', 'Задачи с датами отсутсвуют');
            return;
        }

        $periods = $this->buildPeriods($minDate, $maxDate, $scale);

        $fixedCols    = 6;
        $fixedHeaders = ['WBS', 'Задача', 'Начало', 'Окончание', 'Дней', '%'];
        $fixedWidths  = [10, 38, 11, 11, 7, 5];
        $timeColWidth = match($scale) { 'day' => 5, 'week' => 7, default => 11 };

        foreach ($fixedWidths as $i => $w) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth($w);
        }

        $periodCount = count($periods);
        for ($i = 0; $i < $periodCount; $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($fixedCols + $i + 1))->setWidth($timeColWidth);
        }

        $lastCol = Coordinate::stringFromColumnIndex($fixedCols + $periodCount);

        $coverRows = $this->buildMiniCover($sheet, $schedule, $scale, "A1:{$lastCol}1");
        $headerRow = $coverRows + 1;

        foreach ($fixedHeaders as $i => $h) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$col}{$headerRow}", $h);
        }
        foreach ($periods as $i => $period) {
            $col = Coordinate::stringFromColumnIndex($fixedCols + $i + 1);
            $sheet->setCellValue("{$col}{$headerRow}", $period['label']);
            $rot = match($scale) { 'day' => 90, 'week' => 60, default => 45 };
            $sheet->getStyle("{$col}{$headerRow}")->getAlignment()->setTextRotation($rot)->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $this->applyTableHeader($sheet, "A{$headerRow}:{$lastCol}{$headerRow}");
        $hHeight = match($scale) { 'day' => 60, 'week' => 40, default => 35 };
        $sheet->getRowDimension($headerRow)->setRowHeight($hHeight);

        $today       = Carbon::today();
        $todayColIdx = null;
        foreach ($periods as $i => $period) {
            if ($today->gte($period['start']) && $today->lte($period['end'])) {
                $todayColIdx = $i;
                break;
            }
        }
        if ($todayColIdx !== null) {
            $todayCol = Coordinate::stringFromColumnIndex($fixedCols + $todayColIdx + 1);
            $sheet->getStyle("{$todayCol}{$headerRow}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['argb' => 'FFDC2626']],
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            ]);
        }

        $dataRow = $headerRow + 1;
        foreach ($tasks as $task) {
            $isCritical = (bool)($task->is_critical ?? false);
            $type       = $this->enumVal($task->task_type, 'task');
            $taskStart  = $task->planned_start_date ? Carbon::parse($task->planned_start_date) : null;
            $taskEnd    = $task->planned_end_date   ? Carbon::parse($task->planned_end_date)   : null;
            $progress   = (int)($task->progress_percent ?? 0);
            $isSummary  = ($type === 'summary' || $type === 'container');

            $sheet->setCellValue('A' . $dataRow, $task->wbs_code ?? '');
            $sheet->setCellValue('B' . $dataRow, ($task->name ?? ''));
            $sheet->setCellValue('C' . $dataRow, $taskStart ? $taskStart->format('d.m.Y') : '');
            $sheet->setCellValue('D' . $dataRow, $taskEnd   ? $taskEnd->format('d.m.Y')   : '');
            $sheet->setCellValue('E' . $dataRow, $task->planned_duration_days ?? '');
            $sheet->setCellValue('F' . $dataRow, $progress . '%');

            $rowBg = $this->getTaskRowBgColor($task, $isCritical);
            $sheet->getStyle("A{$dataRow}:F{$dataRow}")->applyFromArray([
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'color' => ['argb' => 'FF' . $rowBg]],
                'font'      => [
                    'bold'  => ($isSummary || $isCritical),
                    'size'  => $isSummary ? 10 : 9,
                    'color' => ['argb' => $isCritical ? 'FF' . self::CRITICAL_FG : 'FF111827'],
                ],
                'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFE5E7EB']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getStyle('B' . $dataRow)->getAlignment()->setIndent(max(0, ($task->level ?? 0) - 1));

            $activePeriodIdxs = [];
            $hasIntervals = $task->intervals && $task->intervals->isNotEmpty();

            if ($hasIntervals) {
                foreach ($task->intervals as $interval) {
                    $intStart = Carbon::parse($interval->start_date)->startOfDay();
                    $intEnd = Carbon::parse($interval->end_date)->endOfDay();
                    foreach ($periods as $i => $period) {
                        if ($intStart->lte($period['end']) && $intEnd->gte($period['start'])) {
                            $activePeriodIdxs[] = $i;
                        }
                    }
                }
                $activePeriodIdxs = array_values(array_unique($activePeriodIdxs));
                sort($activePeriodIdxs);
            } elseif ($taskStart && $taskEnd) {
                foreach ($periods as $i => $period) {
                    if ($taskStart->lte($period['end']) && $taskEnd->gte($period['start'])) {
                        $activePeriodIdxs[] = $i;
                    }
                }
            }
            $donePeriodCount = $progress > 0 ? (int)round(count($activePeriodIdxs) * $progress / 100) : 0;
            $barColor  = $this->getBarColor($type, $isCritical);
            $doneColor = $this->getDoneColor($type, $isCritical);

            for ($i = 0; $i < $periodCount; $i++) {
                $col     = Coordinate::stringFromColumnIndex($fixedCols + $i + 1);
                $cellRef = "{$col}{$dataRow}";
                $posInActive = array_search($i, $activePeriodIdxs, true);
                $isBar       = $posInActive !== false;

                if ($isBar) {
                    $cellColor = ($posInActive < $donePeriodCount) ? $doneColor : $barColor;
                    $sheet->getStyle($cellRef)->applyFromArray([
                        'fill'      => ['fillType' => Fill::FILL_SOLID, 'color' => ['argb' => 'FF' . $cellColor]],
                        'borders'   => [
                            'top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFCBD5E1']],
                            'bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFCBD5E1']],
                            'left' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFCBD5E1']],
                            'right' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFCBD5E1']],
                        ],
                        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_CENTER],
                    ]);
                    if ($type === 'milestone') {
                        $sheet->setCellValue($cellRef, '◆');
                        $sheet->getStyle($cellRef)->getFont()->setBold(true)->setColor(new Color('FF' . $barColor));
                        $sheet->getStyle($cellRef)->getFill()->setFillType(Fill::FILL_NONE);
                    }
                } else {
                    $altBg = ($dataRow % 2 === 0) ? 'F9FAFB' : 'FFFFFF';
                    $sheet->getStyle($cellRef)->applyFromArray([
                        'fill'    => ['fillType' => Fill::FILL_SOLID, 'color' => ['argb' => 'FF' . $altBg]],
                        'borders' => [
                            'top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFE5E7EB']],
                            'bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFE5E7EB']],
                            'left' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFE5E7EB']],
                            'right' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFE5E7EB']],
                        ],
                    ]);
                }

                if ($todayColIdx === $i) {
                    $sheet->getStyle($cellRef)->getBorders()->getLeft()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setARGB('FFDC2626');
                }
            }

            $sheet->getRowDimension($dataRow)->setRowHeight(18);
            $dataRow++;
        }

        $freezeCol = Coordinate::stringFromColumnIndex($fixedCols + 1);
        $sheet->freezePane("{$freezeCol}" . ($headerRow + 1));
    }

    private function flattenTasksHierarchically(Collection $tasks): Collection
    {
        $grouped = $tasks->groupBy('parent_task_id');
        $result = collect();

        // Save relations to restore them later, because groupBy/sortBy might strip them in some array iterations
        $tasksMap = $tasks->keyBy('id');

        $flatten = function ($parentId) use (&$flatten, $grouped, &$result, $tasksMap) {
            $children = $grouped->get($parentId, collect())->sortBy('sort_order');
            foreach ($children as $child) {
                // Restore intervals relation
                if ($tasksMap->has($child->id) && $tasksMap->get($child->id)->relationLoaded('intervals')) {
                    $child->setRelation('intervals', $tasksMap->get($child->id)->intervals);
                }
                
                $result->push($child);
                $flatten($child->id);
            }
        };

        $flatten(null); // start with root tasks

        return $result;
    }

    private function buildPeriods(Carbon $minDate, Carbon $maxDate, string $scale): array
    {
        $periods = [];

        if ($scale === 'day') {
            $cur = $minDate->copy()->startOfDay();
            $end = $maxDate->copy()->endOfDay();
            while ($cur->lte($end)) {
                $periods[] = [
                    'label' => $cur->format('d'),
                    'start' => $cur->copy(),
                    'end'   => $cur->copy()->endOfDay(),
                ];
                $cur->addDay();
            }
        } elseif ($scale === 'week') {
            $cur = $minDate->copy()->startOfWeek(Carbon::MONDAY);
            $end = $maxDate->copy()->endOfWeek(Carbon::SUNDAY);
            while ($cur->lte($end)) {
                $periods[] = [
                    'label' => $cur->format('d.m'),
                    'start' => $cur->copy(),
                    'end'   => $cur->copy()->endOfWeek(Carbon::SUNDAY),
                ];
                $cur->addWeek();
            }
        } else {
            $cur = $minDate->copy()->startOfMonth();
            $end = $maxDate->copy()->endOfMonth();
            while ($cur->lte($end)) {
                $periods[] = [
                    'label' => $cur->format('M\'y'),
                    'start' => $cur->copy(),
                    'end'   => $cur->copy()->endOfMonth(),
                ];
                $cur->addMonth();
            }
        }

        return $periods;
    }

    private function buildMiniCover(Worksheet $sheet, ProjectSchedule $schedule, string $scale, string $rangeOrCell): int
    {
        $scaleLabels = ['month' => 'Масштаб: Месяц', 'week' => 'Масштаб: Неделя', 'day' => 'Масштаб: День'];

        $isRange = str_contains($rangeOrCell, ':');
        $startCell = $isRange ? explode(':', $rangeOrCell)[0] : $rangeOrCell;
        $startRow  = (int)preg_replace('/[^0-9]/', '', $startCell);
        $startCol  = preg_replace('/[^A-Z]/', '', $startCell);
        $endCol    = $isRange ? preg_replace('/[^A-Z]/', '', explode(':', $rangeOrCell)[1]) : $startCol;

        $row1 = $startRow;
        $row2 = $startRow + 1;
        $row3 = $startRow + 2;
        $row4 = $startRow + 3;

        $r1Range = "{$startCol}{$row1}:{$endCol}{$row1}";
        $r2Range = "{$startCol}{$row2}:{$endCol}{$row2}";
        $r3Range = "{$startCol}{$row3}:{$endCol}{$row3}";
        $r4Range = "{$startCol}{$row4}:{$endCol}{$row4}";

        if ($isRange) {
            $sheet->mergeCells($r1Range);
            $sheet->mergeCells($r2Range);
            $sheet->mergeCells($r3Range);
            $sheet->mergeCells($r4Range);
        }

        $sheet->setCellValue("{$startCol}{$row1}", 'ProHelper — Диаграмма Ганта');
        $sheet->getStyle($r1Range)->applyFromArray([
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'color' => ['argb' => 'FF' . self::BRAND_DARK]],
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 1],
        ]);
        $sheet->getRowDimension($row1)->setRowHeight(26);

        $sheet->setCellValue("{$startCol}{$row2}", $schedule->name);
        $sheet->getStyle($r2Range)->applyFromArray([
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'color' => ['argb' => 'FF' . self::BRAND_ACCENT]],
            'font'      => ['bold' => true, 'size' => 11, 'color' => ['argb' => 'FFFFFFFF']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 1],
        ]);
        $sheet->getRowDimension($row2)->setRowHeight(22);

        $project = $schedule->project?->name ?? '';
        $meta = ($project ? "Проект: {$project}   " : '') .
                ($scaleLabels[$scale] ?? $scale) .
                '   Дата экспорта: ' . Carbon::now()->format('d.m.Y H:i');
        $sheet->setCellValue("{$startCol}{$row3}", $meta);
        $sheet->getStyle($r3Range)->applyFromArray([
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'color' => ['argb' => 'FFE8F4FD']],
            'font'      => ['size' => 9, 'italic' => true, 'color' => ['argb' => 'FF374151']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 1],
        ]);
        $sheet->getRowDimension($row3)->setRowHeight(16);

        $sheet->getStyle($r4Range)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['argb' => 'FF' . self::BRAND_DARK]],
        ]);
        $sheet->getRowDimension($row4)->setRowHeight(4);

        return 4;
    }

    private function getBarColor(string $type, bool $isCritical): string
    {
        if ($isCritical)                                  return 'EF4444';
        if ($type === 'summary' || $type === 'container') return '3B82F6';
        if ($type === 'milestone')                        return 'F59E0B';
        return '22C55E';
    }

    private function getDoneColor(string $type, bool $isCritical): string
    {
        if ($isCritical)                                  return '991B1B';
        if ($type === 'summary' || $type === 'container') return '1D4ED8';
        if ($type === 'milestone')                        return 'B45309';
        return '15803D';
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
            $statusValue = $task->status instanceof \BackedEnum ? $task->status->value : (string)($task->status ?? 'not_started');
            $sheet->setCellValue("J{$dataRow}", self::STATUS_LABELS[$statusValue] ?? $statusValue);
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
            $sheet->getStyle("F{$dataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("G{$dataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("L{$dataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

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

            $depTypeRaw   = $this->enumVal($dep->dependency_type, 'FS');
            $depTypeLabel = self::DEPENDENCY_TYPES[$depTypeRaw] ?? $depTypeRaw;

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

        $type = $this->enumVal($task->task_type, 'task');

        if ($type === 'summary' || $type === 'container') {
            return self::SUMMARY_BG;
        }

        if ($type === 'milestone') {
            return self::MILESTONE_BG;
        }

        $status = $this->enumVal($task->status, 'not_started');
        return self::STATUS_COLORS[$status] ?? 'FFFFFF';
    }

    private function getTaskTypeLabel(mixed $type): string
    {
        $value = $this->enumVal($type, 'task');

        return match ($value) {
            'summary', 'container' => 'Суммарная',
            'milestone'            => 'Веха',
            default                => 'Работа',
        };
    }

    private function getScheduleStatusLabel(mixed $status): string
    {
        $value = $this->enumVal($status, '');

        return match ($value) {
            'draft'     => 'Черновик',
            'active'    => 'Активный',
            'on_hold'   => 'На паузе',
            'completed' => 'Завершён',
            'cancelled' => 'Отменён',
            default     => $value ?: '—',
        };
    }

    private function enumVal(mixed $value, string $default = ''): string
    {
        if ($value === null) {
            return $default;
        }
        return $value instanceof \BackedEnum ? $value->value : (string)$value;
    }
}
