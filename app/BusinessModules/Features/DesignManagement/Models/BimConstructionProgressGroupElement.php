<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BimConstructionProgressGroupElement extends Model
{
    protected $table = 'bim_construction_progress_group_elements';

    protected $fillable = ['group_id', 'version_id', 'schedule_task_id', 'element_id', 'status', 'ended_at'];

    protected $casts = ['element_id' => 'integer', 'ended_at' => 'datetime'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(BimConstructionProgressGroup::class, 'group_id');
    }
}
