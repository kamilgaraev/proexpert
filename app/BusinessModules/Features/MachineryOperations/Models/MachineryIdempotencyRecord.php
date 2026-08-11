<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Models;

use Illuminate\Database\Eloquent\Model;

final class MachineryIdempotencyRecord extends Model
{
    protected $fillable = [
        'organization_id',
        'actor_user_id',
        'idempotency_key',
        'operation_type',
        'request_hash',
        'response_type',
        'response_id',
    ];
}
