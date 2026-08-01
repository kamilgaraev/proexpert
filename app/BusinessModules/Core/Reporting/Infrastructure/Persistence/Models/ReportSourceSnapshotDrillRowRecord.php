<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;

final class ReportSourceSnapshotDrillRowRecord extends Model
{
    protected $table = 'report_source_snapshot_drill_rows';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['ordinal' => 'integer', 'payload' => 'array', 'created_at' => 'immutable_datetime'];
}
