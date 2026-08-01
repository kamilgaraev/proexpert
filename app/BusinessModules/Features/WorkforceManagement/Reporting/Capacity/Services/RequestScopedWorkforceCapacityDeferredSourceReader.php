<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityDeferredSourceReader;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityRequestScopedFrozenSourceGateway;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCohortKey;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityFrozenSourceProjection;
use InvalidArgumentException;

final readonly class RequestScopedWorkforceCapacityDeferredSourceReader implements WorkforceCapacityDeferredSourceReader
{
    public function __construct(private WorkforceCapacityRequestScopedFrozenSourceGateway $gateway) {}

    public function nextKeys(int $captureRequestId, ?string $afterSortIdentity, int $limit): array
    {
        if ($captureRequestId < 1 || $limit < 1 || $limit > 65) {
            throw new InvalidArgumentException('workforce_capacity_deferred_key_limit_invalid');
        }

        $keys = $this->gateway->nextKeys($captureRequestId, $afterSortIdentity, $limit);
        $previous = $afterSortIdentity;
        foreach ($keys as $key) {
            if (! $key instanceof WorkforceCapacityCohortKey
                || ($previous !== null && strcmp($previous, $key->sortIdentity()) >= 0)) {
                throw new InvalidArgumentException('workforce_capacity_deferred_key_stream_invalid');
            }
            $previous = $key->sortIdentity();
        }
        if (count($keys) > $limit) {
            throw new InvalidArgumentException('workforce_capacity_deferred_key_stream_invalid');
        }

        return $keys;
    }

    public function readBatch(int $captureRequestId, array $keys): array
    {
        if ($captureRequestId < 1 || $keys === [] || count($keys) > 64) {
            throw new InvalidArgumentException('workforce_capacity_deferred_source_batch_invalid');
        }

        $sources = [];
        foreach ($keys as $key) {
            if (! $key instanceof WorkforceCapacityCohortKey) {
                throw new InvalidArgumentException('workforce_capacity_deferred_source_batch_invalid');
            }
            $sources[$key->identity()] = [
                'staff_unit' => null,
                'assignments' => [],
                'schedules' => [],
                'schedule_days' => [],
                'absences' => [],
                'business_trips' => [],
                'employee_lifecycle' => [],
                'gaps' => [],
            ];
        }

        foreach ($this->gateway->sourceProjections($captureRequestId, $keys) as $projection) {
            if (! $projection instanceof WorkforceCapacityFrozenSourceProjection
                || ! isset($sources[$projection->cohortIdentity])) {
                throw new InvalidArgumentException('workforce_capacity_frozen_projection_invalid');
            }
            $target = match ($projection->sourceType) {
                'staff_unit' => 'staff_unit',
                'assignment' => 'assignments',
                'employee_lifecycle' => 'employee_lifecycle',
                'schedule' => 'schedules',
                'schedule_day' => 'schedule_days',
                'absence' => 'absences',
                'business_trip' => 'business_trips',
            };
            if ($target === 'staff_unit') {
                if ($sources[$projection->cohortIdentity]['staff_unit'] !== null) {
                    throw new InvalidArgumentException('workforce_capacity_frozen_projection_duplicate');
                }
                $sources[$projection->cohortIdentity]['staff_unit'] = $projection->payload;
            } else {
                $sources[$projection->cohortIdentity][$target][] = $projection->payload;
            }
        }

        foreach ($sources as &$source) {
            if ($source['staff_unit'] === null) {
                $source['gaps'][] = 'source_contract_missing';
            }
        }
        unset($source);

        return $sources;
    }
}
