<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Models;

use Illuminate\Database\Eloquent\Model;

final class DesignIfcModelElement extends Model
{
    protected $table = 'design_ifc_model_elements';

    protected $fillable = [
        'organization_id', 'project_id', 'version_id', 'derivative_id', 'express_id',
        'global_id', 'category', 'name', 'properties', 'classifications',
    ];

    protected $casts = [
        'express_id' => 'integer',
        'properties' => 'array',
        'classifications' => 'array',
    ];
}
