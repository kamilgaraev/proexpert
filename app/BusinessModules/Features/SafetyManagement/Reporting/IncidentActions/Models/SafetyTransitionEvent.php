<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class SafetyTransitionEvent extends Model
{
    public $timestamps = false;

    protected $table = 'safety_transition_events';

    protected $guarded = [];

    protected $casts = [
        'due_date' => 'immutable_date',
        'occurred_at' => 'immutable_datetime',
        'recorded_at' => 'immutable_datetime',
        'event_version' => 'integer',
    ];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('safety_transition_event_immutable'));
        self::deleting(static fn (): never => throw new LogicException('safety_transition_event_immutable'));
    }
}
