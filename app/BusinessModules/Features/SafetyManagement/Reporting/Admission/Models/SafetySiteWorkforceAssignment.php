<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class SafetySiteWorkforceAssignment extends Model
{
    protected $table = 'safety_site_workforce_assignments';

    protected $guarded = [];

    protected $casts = [
        'valid_from' => 'immutable_date',
        'valid_to' => 'immutable_date',
    ];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('safety_site_workforce_assignment_immutable'));
        self::deleting(static fn (): never => throw new LogicException('safety_site_workforce_assignment_immutable'));
    }
}
