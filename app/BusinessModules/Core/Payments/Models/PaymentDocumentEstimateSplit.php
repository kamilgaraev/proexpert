<?php

namespace App\BusinessModules\Core\Payments\Models;

use App\Models\EstimateItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentDocumentEstimateSplit extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_document_id',
        'estimate_item_id',
        'amount',
        'percentage',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'percentage' => 'decimal:2',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(PaymentDocument::class, 'payment_document_id');
    }

    public function estimateItem(): BelongsTo
    {
        return $this->belongsTo(EstimateItem::class);
    }
}

