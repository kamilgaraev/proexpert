<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

abstract class CrmModel extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where($this->getTable() . '.organization_id', $organizationId);
    }
}
