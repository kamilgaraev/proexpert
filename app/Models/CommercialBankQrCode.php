<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialBankQrCode extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending'];

    protected $casts = ['amount_minor' => 'integer', 'request' => 'array', 'expires_at' => 'immutable_datetime'];
}
