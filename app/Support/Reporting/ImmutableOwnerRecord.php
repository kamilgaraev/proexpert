<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

trait ImmutableOwnerRecord
{
    protected static function bootImmutableOwnerRecord(): void
    {
        static::updating(static function (): never {
            throw new LogicException('reporting_record_is_immutable');
        });
        static::deleting(static function (): never {
            throw new LogicException('reporting_record_is_immutable');
        });
    }

    protected function performUpdate(Builder $query)
    {
        throw new LogicException('reporting_record_is_immutable');
    }

    public function delete()
    {
        if ($this->exists) {
            throw new LogicException('reporting_record_is_immutable');
        }

        return parent::delete();
    }
}
