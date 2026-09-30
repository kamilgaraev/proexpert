<?php

declare(strict_types=1);

namespace App\Models\Credits;

use Illuminate\Database\Eloquent\Model;

final class AICreditProviderUsage extends Model
{
    protected $table = 'ai_credit_provider_usages';

    protected $fillable = ['organization_id', 'ai_credit_reservation_id', 'usage_key', 'provider', 'model', 'operation', 'cost_micro_rub', 'is_successful', 'metadata', 'occurred_at'];

    protected $casts = ['cost_micro_rub' => 'integer', 'is_successful' => 'boolean', 'metadata' => 'array', 'occurred_at' => 'immutable_datetime'];
}
