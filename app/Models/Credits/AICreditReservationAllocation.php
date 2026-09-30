<?php

declare(strict_types=1);

namespace App\Models\Credits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AICreditReservationAllocation extends Model
{
    protected $table = 'ai_credit_reservation_allocations';

    protected $fillable = ['ai_credit_reservation_id', 'ai_credit_lot_id', 'reserved_minor', 'consumed_minor'];

    protected $casts = ['reserved_minor' => 'integer', 'consumed_minor' => 'integer'];

    public function lot(): BelongsTo { return $this->belongsTo(AICreditLot::class, 'ai_credit_lot_id'); }
}
