<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Support;

use DomainException;
use Illuminate\Database\Eloquent\Model;

trait ImmutableReportingRecord
{
    protected static function bootImmutableReportingRecord(): void
    {
        static::updating(static function (Model $model): never {
            throw new DomainException($model->getTable().' records are immutable.');
        });
        static::deleting(static function (Model $model): never {
            throw new DomainException($model->getTable().' records are immutable.');
        });
    }
}
