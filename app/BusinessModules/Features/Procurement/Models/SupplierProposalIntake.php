<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Models;

use App\BusinessModules\Features\Procurement\Enums\SupplierProposalIntakeSourceEnum;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierProposalIntake extends Model
{
    protected $table = 'supplier_proposal_intakes';

    protected $fillable = [
        'organization_id',
        'supplier_proposal_id',
        'supplier_party_id',
        'source',
        'received_at',
        'entered_by',
        'external_reference',
        'comment',
        'attachment_ids',
    ];

    protected $casts = [
        'source' => SupplierProposalIntakeSourceEnum::class,
        'received_at' => 'datetime',
        'attachment_ids' => 'array',
    ];

    protected $attributes = [
        'attachment_ids' => '[]',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function supplierProposal(): BelongsTo
    {
        return $this->belongsTo(SupplierProposal::class);
    }

    public function supplierParty(): BelongsTo
    {
        return $this->belongsTo(SupplierParty::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
