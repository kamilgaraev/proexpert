<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ReportSavedViewRecord extends Model
{
    use SoftDeletes;

    protected $table = 'report_saved_views';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['organization_id' => 'integer', 'owner_id' => 'integer', 'current_revision' => 'integer', 'filters_json' => 'array', 'comparison_json' => 'array', 'sort_json' => 'array', 'columns_json' => 'array', 'is_default' => 'boolean', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime', 'deleted_at' => 'immutable_datetime'];
}
