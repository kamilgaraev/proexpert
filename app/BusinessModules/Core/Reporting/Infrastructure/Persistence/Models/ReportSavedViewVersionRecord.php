<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;

final class ReportSavedViewVersionRecord extends Model
{
    protected $table = 'report_saved_view_versions';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'integer',
        'owner_id' => 'integer',
        'revision' => 'integer',
        'content_json' => 'array',
        'created_at' => 'immutable_datetime',
    ];
}
