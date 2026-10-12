<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class EstimateOperationRow extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['payload' => 'array', 'has_conflicts' => 'boolean'];
}
