<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

abstract class BudgetingModel extends Model
{
    protected static function booted(): void
    {
        static::creating(static function (Model $model): void {
            if (!$model->getAttribute('uuid')) {
                $model->setAttribute('uuid', (string) Str::uuid());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
