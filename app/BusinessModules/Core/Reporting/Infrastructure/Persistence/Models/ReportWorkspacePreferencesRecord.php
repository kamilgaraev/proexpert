<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;

final class ReportWorkspacePreferencesRecord extends Model
{
    protected $table = 'report_workspace_preferences';

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'integer',
        'owner_id' => 'integer',
        'recent_report_codes' => 'array',
        'favourite_report_codes' => 'array',
        'display_preferences' => 'array',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];
}
