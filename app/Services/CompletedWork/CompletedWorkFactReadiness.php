<?php

declare(strict_types=1);

namespace App\Services\CompletedWork;

use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;

use function trans_message;

final class CompletedWorkFactReadiness
{
    public function assertReady(CompletedWork $work): void
    {
        if ($work->isResourceFact()) {
            return;
        }

        if ($work->effectiveCompletedQuantity() < 0.001) {
            throw new BusinessLogicException(trans_message('completed_work.fact_quantity_required'), 422);
        }

        if ($work->work_origin_type === CompletedWork::ORIGIN_JOURNAL) {
            return;
        }

        $work->loadMissing([
            'scheduleTask.measurementUnit',
            'scheduleTask.estimateItem.measurementUnit',
            'estimateItem.measurementUnit',
            'workType.measurementUnit',
        ]);

        $explicitUnit = $this->text(data_get($work->additional_info, 'unit_of_measurement'));
        $unit = $work->scheduleTask?->measurementUnit?->short_name
            ?? $work->scheduleTask?->estimateItem?->measurementUnit?->short_name
            ?? $work->estimateItem?->measurementUnit?->short_name
            ?? $explicitUnit
            ?? $work->workType?->measurementUnit?->short_name;

        if ($this->text($unit) === null) {
            throw new BusinessLogicException(trans_message('completed_work.fact_unit_required'), 422);
        }

        if ($work->schedule_task_id !== null || $work->estimate_item_id !== null) {
            return;
        }

        if ($this->text(data_get($work->additional_info, 'work_name')) === null) {
            throw new BusinessLogicException(trans_message('completed_work.fact_name_required'), 422);
        }

        if ($this->text(data_get($work->additional_info, 'location')) === null) {
            throw new BusinessLogicException(trans_message('completed_work.fact_location_required'), 422);
        }
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim($value);

        return $text === '' ? null : $text;
    }
}
