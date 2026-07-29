<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

final class ChangeRequestVersion extends Model
{
    protected $guarded = [];

    protected $casts = ['effective_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new DomainException('change_request_version_immutable'));
        static::deleting(static fn (): never => throw new DomainException('change_request_version_immutable'));
    }
}
