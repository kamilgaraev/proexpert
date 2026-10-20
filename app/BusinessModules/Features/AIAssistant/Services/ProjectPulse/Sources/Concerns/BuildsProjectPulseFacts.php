<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\Concerns;

use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext;
use App\BusinessModules\Features\SiteRequests\Enums\PersonnelTypeEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
        $actor = $context->userId === null ? null : \App\Models\User::find($context->userId);
        $query = DB::table($table);
        if ($actor === null) {
            return $query->whereRaw('1 = 0');
        }
        app(\App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy::class)->scopeTable($query, $actor, $context->organizationId, $table, true);
        if ($context->projectId !== null && $table === 'projects') {
            $query->where('projects.id', $context->projectId);
        }
        try {
            $columns = array_fill_keys(array_map(strtolower(...), Schema::getColumnListing($table)), true);
        } catch (Throwable) {
            $columns = null;
        }
        $hasColumn = fn (string $column): bool => $columns === null
            ? $this->hasColumn($table, $column)
            : isset($columns[$column]);

        return $query
            ->when($hasColumn('organization_id'), fn (Builder $query) => $query->where($table . '.organization_id', $context->organizationId))
            ->when($context->projectId !== null && $hasColumn('project_id'), fn (Builder $query) => $query->where($table . '.project_id', $context->projectId))
            ->when($hasColumn('deleted_at'), fn (Builder $query) => $query->whereNull($table . '.deleted_at'));
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

        return DB::table('projects')
            ->where('projects.id', $projectId)
            ->value('name');
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

    private function siteRequestLabelColumns(): array
    {
        return [
            'site_requests.request_type',
            'site_requests.personnel_type',
            'site_requests.personnel_count',
            'site_requests.work_location',
            'site_requests.work_start_date',
            'site_requests.work_end_date',
            'site_requests.location',
            'site_requests.delivery_address',
            'site_requests.equipment_location',
            'site_requests.required_date',
        ];
    }

    private function siteRequestTitle(object $row): string
    {
        return trim((string) ($row->title ?? '')) ?: 'Заявка с объекта';
    }

    private function siteRequestLabel(object $row): string
    {
        $type = SiteRequestTypeEnum::fromStoredValue($row->request_type ?? null);
        $parts = [$this->siteRequestTitle($row), $type->label()];
        if ($type === SiteRequestTypeEnum::PERSONNEL_REQUEST) {
            $personnelType = PersonnelTypeEnum::tryFrom((string) ($row->personnel_type ?? ''));
            if ($personnelType !== null) {
                $parts[] = $personnelType->label();
            }
            if ((int) ($row->personnel_count ?? 0) > 0) {
                $parts[] = (int) $row->personnel_count . ' чел.';
            }
        }
        $typedLocation = match ($type) {
            SiteRequestTypeEnum::MATERIAL_REQUEST => $row->delivery_address ?? '',
            SiteRequestTypeEnum::EQUIPMENT_REQUEST => $row->equipment_location ?? '',
            SiteRequestTypeEnum::PERSONNEL_REQUEST => $row->work_location ?? '',
            default => '',
        };
        $location = trim((string) $typedLocation) ?: trim((string) ($row->location ?? ''));
        if ($location !== '') {
            $parts[] = Str::limit($location, 160, '…');
        }
        if ($type === SiteRequestTypeEnum::PERSONNEL_REQUEST) {
            $start = empty($row->work_start_date) ? null : CarbonImmutable::parse($row->work_start_date)->format('d.m.Y');
            $end = empty($row->work_end_date) ? null : CarbonImmutable::parse($row->work_end_date)->format('d.m.Y');
            if ($start !== null || $end !== null) {
                $parts[] = $start !== null && $end !== null ? $start . ' — ' . $end : ($start !== null ? 'с ' . $start : 'до ' . $end);
            }
        }
        if (!empty($row->required_date)) {
            $parts[] = 'Нужно к ' . CarbonImmutable::parse($row->required_date)->format('d.m.Y');
        }
        return implode(' · ', $parts);
    }

    private function empty(): Collection
    {
        return collect();
    }
}
