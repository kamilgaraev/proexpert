<?php

namespace App\BusinessModules\Core\Payments\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Project;

class PaymentDocumentSplit extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_document_id',
        'project_id',
        'cost_item_id',
        'amount',
        'description',
        'metadata'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(PaymentDocument::class, 'payment_document_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    // Cost item relationship would be added when BudgetEstimates module is fully integrated
    // public function costItem(): BelongsTo ...
}

