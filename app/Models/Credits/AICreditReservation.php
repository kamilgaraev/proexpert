<?php

declare(strict_types=1);

namespace App\Models\Credits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AICreditReservation extends Model
{
    protected $table = 'ai_credit_reservations';

    protected $fillable = ['public_id', 'organization_id', 'user_id', 'ai_credit_quote_id', 'request_id', 'conversation_id', 'reserved_minor', 'consumed_minor', 'status', 'finalized_at', 'cancelled_at'];

    protected $casts = ['reserved_minor' => 'integer', 'consumed_minor' => 'integer', 'finalized_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];

    public function allocations(): HasMany { return $this->hasMany(AICreditReservationAllocation::class, 'ai_credit_reservation_id'); }
}
