<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class QualityDefectTransitionEvent extends Model
{
    public $timestamps = false;

    protected $table = 'quality_defect_transition_events';

    protected $guarded = [];

    protected $casts = [
        'due_date' => 'immutable_date',
        'occurred_at' => 'immutable_datetime',
        'recorded_at' => 'immutable_datetime',
        'event_version' => 'integer',
        'evidence_refs' => 'array',
    ];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('quality_defect_transition_event_immutable'));
        self::deleting(static fn (): never => throw new LogicException('quality_defect_transition_event_immutable'));
    }
}
