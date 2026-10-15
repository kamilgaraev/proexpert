<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialBankWebhookEvent extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['operation_date' => 'immutable_date', 'processed_at' => 'immutable_datetime'];
}
