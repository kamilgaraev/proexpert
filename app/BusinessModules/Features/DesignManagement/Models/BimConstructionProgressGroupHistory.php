<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Models;

use Illuminate\Database\Eloquent\Model;

final class BimConstructionProgressGroupHistory extends Model
{
    protected $table = 'bim_construction_progress_group_history';

    protected $fillable = ['group_id', 'organization_id', 'project_id', 'version_id', 'action', 'revision', 'snapshot', 'reason', 'actor_id'];

    protected $casts = ['revision' => 'integer', 'snapshot' => 'array'];
}
