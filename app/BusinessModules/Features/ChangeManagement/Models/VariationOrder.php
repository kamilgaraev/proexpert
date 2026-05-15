<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class VariationOrder extends Model
{
    protected $table = 'change_management_variation_orders';

    protected $fillable = [
        'organization_id',
        'change_request_id',
        'variation_number',
        'amount',
        'schedule_delta_days',
        'description',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'schedule_delta_days' => 'integer',
    ];

    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(ChangeRequest::class, 'change_request_id');
    }
}
