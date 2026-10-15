<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialBankStatement extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'new', 'is_periodic' => false];

    protected $casts = ['is_periodic' => 'boolean', 'statement_date' => 'immutable_date', 'requested_at' => 'immutable_datetime', 'processed_at' => 'immutable_datetime'];
}
