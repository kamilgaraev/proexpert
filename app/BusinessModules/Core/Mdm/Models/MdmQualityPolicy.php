<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Models;

use Illuminate\Database\Eloquent\Model;

class MdmQualityPolicy extends Model
{
    protected $fillable = [
        'organization_id',
        'entity_type',
        'required_fields',
        'field_weights',
        'validation_rules',
        'min_acceptable_score',
    ];

    protected $casts = [
        'required_fields' => 'array',
        'field_weights' => 'array',
        'validation_rules' => 'array',
        'min_acceptable_score' => 'integer',
    ];
}
