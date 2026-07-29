<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

final class ChangeClaimLink extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new DomainException('change_claim_link_immutable'));
        static::deleting(static fn (): never => throw new DomainException('change_claim_link_immutable'));
    }
}
