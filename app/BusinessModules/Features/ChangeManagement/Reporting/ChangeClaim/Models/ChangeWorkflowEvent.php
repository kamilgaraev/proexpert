<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models;

use Illuminate\Database\Eloquent\Model;

final class ChangeWorkflowEvent extends Model
{
    protected $guarded = [];

    protected $casts = ['occurred_at' => 'immutable_datetime'];
}
