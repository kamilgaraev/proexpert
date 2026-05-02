<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\Concerns;

use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

trait BuildsProjectPulseFacts
{
    private function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            return Schema::hasColumn($table, $column);
        } catch (Throwable) {
            return false;
        }
    }

    private function table(ProjectPulseContext $context, string $table): Builder
    {
        return DB::table($table)
            ->when($this->hasColumn($table, 'organization_id'), fn (Builder $query) => $query->where($table . '.organization_id', $context->organizationId))
            ->when($context->projectId !== null && $this->hasColumn($table, 'project_id'), fn (Builder $query) => $query->where($table . '.project_id', $context->projectId))
            ->when($this->hasColumn($table, 'deleted_at'), fn (Builder $query) => $query->whereNull($table . '.deleted_at'));
    }

    private function limit(): int
    {
        return (int) config('ai-assistant.project_pulse.limits.facts_per_source', 30);
    }

    private function ageDays(ProjectPulseContext $context, mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        try {
            $date = $value instanceof CarbonInterface ? $value : \Carbon\CarbonImmutable::parse((string) $value);

            return max(0, $date->startOfDay()->diffInDays($context->date->startOfDay()));
        } catch (Throwable) {
            return null;
        }
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return ($value instanceof CarbonInterface ? $value : \Carbon\CarbonImmutable::parse((string) $value))->toIso8601String();
        } catch (Throwable) {
            return (string) $value;
        }
    }

    private function projectName(?int $projectId): ?string
    {
        if ($projectId === null || !$this->hasTable('projects')) {
            return null;
        }

        return DB::table('projects')->whereKey($projectId)->value('name');
    }

    private function projectRoute(?int $projectId): ?array
    {
        if ($projectId === null) {
            return null;
        }

        return [
            'type' => 'project',
            'id' => $projectId,
            'label' => 'Проект #' . $projectId,
            'route' => '/projects/' . $projectId,
        ];
    }

    private function empty(): Collection
    {
        return collect();
    }
}
