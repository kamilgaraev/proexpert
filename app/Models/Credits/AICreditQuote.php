<?php

declare(strict_types=1);

namespace App\Models\Credits;

use Illuminate\Database\Eloquent\Model;

final class AICreditQuote extends Model
{
    protected $table = 'ai_credit_quotes';

    protected $fillable = ['public_id', 'organization_id', 'user_id', 'request_key', 'request_hash', 'profile', 'price_version', 'limits', 'pricing', 'min_units_minor', 'max_units_minor', 'expires_at'];

    protected $casts = ['limits' => 'array', 'pricing' => 'array', 'price_version' => 'integer', 'min_units_minor' => 'integer', 'max_units_minor' => 'integer', 'expires_at' => 'immutable_datetime'];
}
