<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateVersion extends Model
{
    protected $fillable = [
        'estimate_id',
        'created_by_user_id',
        'version_number',
        'label',
        'comment',
        'snapshot',
        'total_amount',
        'total_amount_with_vat',
        'total_direct_costs',
    ];

    protected $casts = [
        'snapshot'              => 'array',
        'version_number'        => 'integer',
        'total_amount'          => 'float',
        'total_amount_with_vat' => 'float',
        'total_direct_costs'    => 'float',
    ];

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
