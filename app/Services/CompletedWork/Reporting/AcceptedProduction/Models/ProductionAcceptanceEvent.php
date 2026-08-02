<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Models;

use Illuminate\Database\Eloquent\Model;

final class ProductionAcceptanceEvent extends Model
{
    public $timestamps = false;

    protected $table = 'production_acceptance_events';

    protected $guarded = [];

    protected $casts = [
        'recognized_at' => 'immutable_datetime',
        'evidence_refs' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \LogicException('production_acceptance_event_immutable');
        });
        static::deleting(static function (): never {
            throw new \LogicException('production_acceptance_event_immutable');
        });
    }
}
