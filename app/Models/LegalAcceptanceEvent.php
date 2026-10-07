<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class LegalAcceptanceEvent extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'evidence' => 'array', 'recorded_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Legal evidence must be appended, not overwritten.');
        });
    }
}
