<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Models\Estimate;
use App\Models\EstimateItem;
use Illuminate\Support\Facades\Log;

class AutoSchedulingService
{
    private const DEFAULT_PRODUCTIVITY = [
        'work'      => 500,
        'material'  => 0,
        'machinery' => 0,
        'labor'     => 8,
    ];

    public function generateSchedule(int $estimateId, array $options = []): array
    {
        $estimate = Estimate::with(['sections.items'])->findOrFail($estimateId);

        $startDate    = isset($options['start_date'])
            ? new \DateTimeImmutable($options['start_date'])
            : new \DateTimeImmutable();
        $workdaysPerWeek = (int)($options['workdays_per_week'] ?? 5);

        $cursor  = $startDate;
        $tasks   = [];
        $taskIdx = 1;

        foreach ($estimate->sections as $section) {
            $sectionStart = $cursor;

            $workItems = $section->items->filter(fn($i) => in_array($i->item_type, ['work', 'labor'], true));

            if ($workItems->isEmpty()) {
                continue;
            }

            foreach ($workItems as $item) {
                $duration = $this->estimateDurationDays($item, $workdaysPerWeek);
                $endDate  = $this->addWorkdays($cursor, $duration, $workdaysPerWeek);

                $tasks[] = [
                    'id'           => $taskIdx++,
                    'section_id'   => $section->id,
                    'section_name' => $section->name,
                    'item_id'      => $item->id,
                    'name'         => $item->name,
                    'item_type'    => $item->item_type,
                    'start_date'   => $cursor->format('Y-m-d'),
                    'end_date'     => $endDate->format('Y-m-d'),
                    'duration_days'=> $duration,
                    'quantity'     => $item->quantity,
                    'unit'         => $item->measurementUnit?->name,
                    'total_amount' => $item->total_amount ?? $item->current_total_amount ?? 0,
                ];

                $cursor = $endDate;
            }
        }

        $projectEnd = empty($tasks) ? $startDate : new \DateTimeImmutable(end($tasks)['end_date']);
        $totalDays  = (int)$startDate->diff($projectEnd)->days;

        Log::info("[AutoScheduling] Generated schedule for estimate {$estimateId}: " . count($tasks) . " tasks, " . $totalDays . " days");

        return [
            'estimate_id'   => $estimateId,
            'estimate_name' => $estimate->name,
            'start_date'    => $startDate->format('Y-m-d'),
            'end_date'      => $projectEnd->format('Y-m-d'),
            'total_days'    => $totalDays,
            'tasks_count'   => count($tasks),
            'tasks'         => $tasks,
        ];
    }

    private function estimateDurationDays(EstimateItem $item, int $workdaysPerWeek): int
    {
        $qty          = (float)($item->quantity ?? 1);
        $productivity = self::DEFAULT_PRODUCTIVITY[$item->item_type] ?? 500;

        if ($productivity <= 0 || $qty <= 0) {
            return 1;
        }

        $days = (int)ceil($qty / $productivity);
        return max(1, $days);
    }

    private function addWorkdays(\DateTimeImmutable $start, int $days, int $workdaysPerWeek): \DateTimeImmutable
    {
        $current = $start;
        $added   = 0;
        $weekend = $workdaysPerWeek <= 5 ? [6, 7] : ($workdaysPerWeek <= 6 ? [7] : []);

        while ($added < $days) {
            $current = $current->modify('+1 day');
            $dow = (int)$current->format('N');
            if (!in_array($dow, $weekend, true)) {
                $added++;
            }
        }

        return $current;
    }
}
