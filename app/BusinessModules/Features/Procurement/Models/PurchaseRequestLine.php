<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Models;

use App\Models\Material;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseRequestLine extends Model
{
    protected $table = 'purchase_request_lines';

    protected $fillable = [
        'purchase_request_id',
        'material_id',
        'name',
        'quantity',
        'unit',
        'specification',
        'needed_by',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'needed_by' => 'date',
        'metadata' => 'array',
    ];

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function supplierRequestLines(): HasMany
    {
        return $this->hasMany(SupplierRequestLine::class);
    }
}
