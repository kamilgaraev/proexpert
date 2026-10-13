<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Models;

use Illuminate\Database\Eloquent\Model;

final class DesignDrawingStroke extends Model
{
    protected $fillable = [
        'organization_id', 'project_id', 'version_id', 'client_id', 'author_id',
        'author_name', 'page_number', 'annotations', 'fingerprint', 'revision', 'deleted_at',
    ];

    protected $casts = [
        'annotations' => 'array',
        'revision' => 'integer',
        'page_number' => 'integer',
        'deleted_at' => 'datetime',
    ];
}
