<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Models;

use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposal;
use App\BusinessModules\Features\Tenders\Models\Tender;
use App\Models\Contract;
use App\Models\Project;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CrmConversionOperation extends CrmModel
{
    protected $table = 'crm_conversion_operations';

    protected $fillable = [
        'organization_id',
        'idempotency_key',
        'crm_deal_id',
        'tender_id',
        'commercial_proposal_id',
        'payload_hash',
        'preview_hash',
        'status',
        'project_id',
        'contract_id',
        'result_snapshot',
        'error_code',
        'created_by_user_id',
        'completed_at',
    ];

    protected $casts = [
        'result_snapshot' => 'array',
        'completed_at' => 'datetime',
    ];

    public function deal(): BelongsTo
    {
        return $this->belongsTo(CrmDeal::class, 'crm_deal_id');
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class, 'tender_id');
    }

    public function commercialProposal(): BelongsTo
    {
        return $this->belongsTo(CommercialProposal::class, 'commercial_proposal_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
