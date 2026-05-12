<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OneCExchangeMapping extends Model
{
    protected $fillable = [
        'organization_id',
        'scope',
        'external_id',
        'external_name',
        'local_type',
        'local_id',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
