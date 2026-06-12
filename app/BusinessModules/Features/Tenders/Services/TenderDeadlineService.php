<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Tenders\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class TenderDeadlineService
{
    private const TERMINAL_STATUSES = ['won', 'lost', 'cancelled'];

    public function isOverdue(mixed $dueAt, mixed $completedAt, string $status): bool
    {
        if ($dueAt === null || $completedAt !== null || in_array($status, self::TERMINAL_STATUSES, true)) {
            return false;
        }

        return $this->carbon($dueAt)?->lt(now()) ?? false;
    }

    public function resolveNextDeadline(iterable $deadlines): ?array
    {
        $next = null;

        foreach ($deadlines as $deadline) {
            $item = $this->deadlineArray($deadline);
            $dueAt = $this->carbon($item['due_at'] ?? null);

            if ($dueAt === null || ($item['completed_at'] ?? null) !== null) {
                continue;
            }

            if ($next === null || $dueAt->lt($this->carbon($next['due_at']) ?? now()->addYears(100))) {
                $next = $item;
            }
        }

        return $next;
    }

    public function daysToDeadline(mixed $dueAt): ?int
    {
        $date = $this->carbon($dueAt);

        if ($date === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($date->copy()->startOfDay(), false);
    }

    private function carbon(mixed $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value;
        }

        return Carbon::parse($value);
    }

    private function deadlineArray(mixed $deadline): array
    {
        if (is_array($deadline)) {
            return $deadline;
        }

        return [
            'id' => $deadline->id ?? null,
            'kind' => $deadline->kind ?? null,
            'title' => $deadline->title ?? null,
            'due_at' => $deadline->due_at ?? null,
            'completed_at' => $deadline->completed_at ?? null,
            'responsible_user_id' => $deadline->responsible_user_id ?? null,
            'reminder_policy' => $deadline->reminder_policy ?? [],
            'is_required' => (bool) ($deadline->is_required ?? false),
            'metadata' => $deadline->metadata ?? [],
        ];
    }
}
