<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Models;

use Illuminate\Database\Eloquent\Model;

final class DesignModelSessionEventOrder extends Model
{
    public $timestamps = false;

    protected $fillable = ['organization_id', 'session_id', 'model_set_revision_id', 'client_id', 'user_id',
        'sequences', 'max_sequence', 'leave_sequence'];

    protected $casts = ['sequences' => 'array', 'max_sequence' => 'integer', 'leave_sequence' => 'integer'];
}
