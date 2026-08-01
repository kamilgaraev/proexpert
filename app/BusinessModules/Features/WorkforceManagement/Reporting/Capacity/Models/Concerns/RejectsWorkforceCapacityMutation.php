<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Models\Concerns;

use LogicException;

trait RejectsWorkforceCapacityMutation
{
    public static function bootRejectsWorkforceCapacityMutation(): void
    {
        static::updating(static function (): never {
            throw new LogicException('workforce_capacity_record_is_append_only');
        });
        static::deleting(static function (): never {
            throw new LogicException('workforce_capacity_record_is_append_only');
        });
    }
}
