<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\PresaleEstimates\Models;

use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposal;
use App\BusinessModules\Features\Crm\Models\CrmDeal;
use App\BusinessModules\Features\Tenders\Models\Tender;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class PresaleEstimate extends PresaleEstimateModel
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'current_version_id',
        'accepted_version_id',
        'crm_deal_id',
        'tender_id',
        'commercial_proposal_id',
        'project_id',
        'contract_id',
        'number',
        'title',
        'status',
        'subtotal_amount',
        'discount_amount',
        'vat_amount',
        'total_amount',
        'currency',
        'created_by_user_id',
        'updated_by_user_id',
        'metadata',
    ];

    protected $casts = [
        'project_id' => 'integer',
        'contract_id' => 'integer',
        'subtotal_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'status' => 'draft',
        'currency' => 'RUB',
        'metadata' => '{}',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function crmDeal(): BelongsTo
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

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(PresaleEstimateVersion::class, 'current_version_id');
    }

    public function acceptedVersion(): BelongsTo
    {
        return $this->belongsTo(PresaleEstimateVersion::class, 'accepted_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PresaleEstimateVersion::class, 'presale_estimate_id')
            ->orderByDesc('version_number');
    }
}
