<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Models;

use App\Models\Material;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierRequestLine extends Model
{
    protected $table = 'supplier_request_lines';

    protected $fillable = [
        'supplier_request_id',
        'purchase_request_line_id',
        'material_id',
        'name',
        'quantity',
        'unit',
        'specification',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'metadata' => 'array',
    ];

    public function supplierRequest(): BelongsTo
    {
        return $this->belongsTo(SupplierRequest::class);
    }

    public function purchaseRequestLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestLine::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
