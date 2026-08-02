<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

final class ManagementPnlPolicy extends Model
{
    protected $guarded = [];

    protected $casts = [
        'classification_rules' => 'array',
        'allocation_rules' => 'array',
        'activated_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $policy): void {
            if ($policy->getOriginal('status') === 'active') {
                throw new DomainException('management_pnl_active_policy_immutable');
            }
        });
        static::deleting(static function (self $policy): void {
            if ($policy->status === 'active') {
                throw new DomainException('management_pnl_active_policy_immutable');
            }
        });
    }
}
