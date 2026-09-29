<?php

declare(strict_types=1);

namespace App\Models\Credits;

use Illuminate\Database\Eloquent\Model;

final class AICreditLot extends Model
{
    protected $table = 'ai_credit_lots';

    protected $fillable = ['organization_id', 'source', 'original_minor', 'remaining_minor', 'expires_at', 'commercial_order_id', 'metadata'];

    protected $casts = ['original_minor' => 'integer', 'remaining_minor' => 'integer', 'expires_at' => 'immutable_datetime', 'metadata' => 'array'];
}
