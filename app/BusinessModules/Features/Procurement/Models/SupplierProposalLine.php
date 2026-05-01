<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Models;

use App\Models\Material;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierProposalLine extends Model
{
    protected $table = 'supplier_proposal_lines';

    protected $fillable = [
        'supplier_proposal_id',
        'supplier_request_line_id',
        'material_id',
        'name',
        'quantity',
        'unit',
        'unit_price',
        'total_amount',
        'comment',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function supplierProposal(): BelongsTo
    {
        return $this->belongsTo(SupplierProposal::class);
    }

    public function supplierRequestLine(): BelongsTo
    {
        return $this->belongsTo(SupplierRequestLine::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
