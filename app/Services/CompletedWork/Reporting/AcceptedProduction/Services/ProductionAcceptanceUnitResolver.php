<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use App\Models\CompletedWork;
use App\Models\EstimateItem;
use App\Models\MeasurementUnit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

final class ProductionAcceptanceUnitResolver
{
    public function resolve(CompletedWork $work, int $organizationId, int $projectId, ?string $capturedCode = null): MeasurementUnit
    {
        if ($organizationId < 1 || $projectId < 1
            || (int) $work->organization_id !== $organizationId || (int) $work->project_id !== $projectId) {
            throw new InvalidArgumentException('production_acceptance_scope_mismatch');
        }
        $relations = $this->historicalRelations();
        foreach (array_keys($relations) as $path) {
            $parent = $work;
            foreach (explode('.', $path) as $relation) {
                if (! $parent->relationLoaded($relation)) {
                    break;
                }
                $related = $parent->getRelation($relation);
                if ($related === null) {
                    $parent->unsetRelation($relation);
                    break;
                }
                if (! $related instanceof Model) {
                    break;
                }
                $parent = $related;
            }
        }
        $work->loadMissing($relations);
        $units = [];
        $task = $work->scheduleTask;
        if ($work->schedule_task_id !== null) {
            if ($task === null || (int) $task->organization_id !== $organizationId
                || $task->schedule === null || (int) $task->schedule->organization_id !== $organizationId
                || (int) $task->schedule->project_id !== $projectId) {
                throw new InvalidArgumentException('production_acceptance_scope_mismatch');
            }
            $this->add($units, $task->measurementUnit, $task->measurement_unit_id, $organizationId);
            if ($task->estimate_item_id !== null) {
                $this->estimate($units, $task->estimateItem, $organizationId, $projectId);
            }
        }
        if ($work->estimate_item_id !== null) {
            $this->estimate($units, $work->estimateItem, $organizationId, $projectId);
        }
        $workType = $work->workType;
        if ($workType !== null) {
            if ($workType->organization_id !== null && (int) $workType->organization_id !== $organizationId) {
                throw new InvalidArgumentException('production_acceptance_scope_mismatch');
            }
            $this->add($units, $workType->measurementUnit, $workType->measurement_unit_id, $organizationId);
        }
        if ($units === []) {
            throw new InvalidArgumentException('production_acceptance_unit_unavailable');
        }
        if (count($units) !== 1) {
            throw new InvalidArgumentException('production_acceptance_unit_mismatch');
        }
        $unit = array_values($units)[0];
        if (trim((string) $capturedCode) !== '' && $capturedCode !== (string) $unit->short_name) {
            throw new InvalidArgumentException('production_acceptance_unit_mismatch');
        }

        return $unit;
    }

    public function historicalRelations(string $prefix = ''): array
    {
        $historical = static function (BelongsTo $relation): void {
            $relation->withTrashed();
        };
        $paths = ['scheduleTask', 'scheduleTask.schedule', 'scheduleTask.measurementUnit',
            'scheduleTask.estimateItem', 'scheduleTask.estimateItem.estimate', 'scheduleTask.estimateItem.measurementUnit',
            'estimateItem', 'estimateItem.estimate', 'estimateItem.measurementUnit', 'workType', 'workType.measurementUnit'];

        return array_fill_keys(array_map(static fn (string $path): string => $prefix.$path, $paths), $historical);
    }

    private function estimate(array &$units, ?EstimateItem $item, int $organizationId, int $projectId): void
    {
        if ($item === null || $item->estimate === null
            || (int) $item->estimate->organization_id !== $organizationId || (int) $item->estimate->project_id !== $projectId) {
            throw new InvalidArgumentException('production_acceptance_scope_mismatch');
        }
        $this->add($units, $item->measurementUnit, $item->measurement_unit_id, $organizationId);
    }

    private function add(array &$units, ?MeasurementUnit $unit, ?int $unitId, int $organizationId): void
    {
        if ($unitId === null) {
            return;
        }
        if ($unit === null || (int) $unit->id !== $unitId || trim((string) $unit->short_name) === '') {
            throw new InvalidArgumentException('production_acceptance_unit_unavailable');
        }
        if ((int) $unit->organization_id !== $organizationId && ! $unit->is_system) {
            throw new InvalidArgumentException('production_acceptance_scope_mismatch');
        }
        $units[$unitId] = $unit;
    }
}
