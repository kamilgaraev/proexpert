<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Models;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcurementApprovalPolicy extends Model
{
    protected $table = 'procurement_approval_policies';

    protected $fillable = [
        'organization_id',
        'non_lowest_delta_amount',
        'non_lowest_delta_percent',
        'budget_exceed_amount',
        'external_supplier_requires_identity',
        'prevent_requester_approval',
        'prevent_selector_approval',
        'prevent_intake_author_approval',
        'required_approval_permission',
        'is_active',
    ];

    protected $casts = [
        'non_lowest_delta_amount' => 'decimal:2',
        'non_lowest_delta_percent' => 'decimal:2',
        'budget_exceed_amount' => 'decimal:2',
        'external_supplier_requires_identity' => 'boolean',
        'prevent_requester_approval' => 'boolean',
        'prevent_selector_approval' => 'boolean',
        'prevent_intake_author_approval' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
