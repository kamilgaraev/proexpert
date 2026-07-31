<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ReportSourceSnapshotRecord extends Model
{
    protected $table = 'report_source_snapshots';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'integer',
        'scope_identity' => 'array',
        'watermarks' => 'array',
        'as_of' => 'immutable_datetime',
        'generated_at' => 'immutable_datetime',
        'stale_at' => 'immutable_datetime',
        'ready_at' => 'immutable_datetime',
        'expired_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(ReportSourceSnapshotRowRecord::class, 'snapshot_id');
    }

    public function drillRows(): HasMany
    {
        return $this->hasMany(ReportSourceSnapshotDrillRowRecord::class, 'snapshot_id');
    }
}
