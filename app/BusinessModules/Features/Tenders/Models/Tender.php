<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Tenders\Models;

use App\BusinessModules\Features\Crm\Models\CrmCompany;
use App\BusinessModules\Features\Crm\Models\CrmContact;
use App\BusinessModules\Features\Crm\Models\CrmDeal;
use App\Models\Contract;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Tender extends TenderModel
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'source_id',
        'customer_company_id',
        'customer_contact_id',
        'owner_user_id',
        'crm_deal_id',
        'commercial_proposal_id',
        'project_id',
        'contract_id',
        'number',
        'external_number',
        'external_url',
        'title',
        'description',
        'customer_name',
        'customer_inn',
        'customer_kpp',
        'customer_ogrn',
        'status',
        'priority',
        'risk_level',
        'initial_max_price',
        'budget_missing_reason',
        'expected_bid_amount',
        'final_bid_amount',
        'final_bid_amount_missing_reason',
        'winner_amount',
        'currency',
        'published_at',
        'questions_deadline_at',
        'submission_deadline_at',
        'submitted_at',
        'submitted_by_user_id',
        'submission_confirmation_file_id',
        'submission_confirmation_url',
        'opening_at',
        'auction_at',
        'result_expected_at',
        'result_published_at',
        'next_deadline_at',
        'go_no_go_decision',
        'go_no_go_reason',
        'decided_by_user_id',
        'decided_at',
        'lost_reason',
        'cancel_reason',
        'winner_name',
        'requirements_summary',
        'analysis_summary',
        'requirements',
        'evaluation_criteria',
        'metadata',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'initial_max_price' => 'decimal:2',
        'expected_bid_amount' => 'decimal:2',
        'final_bid_amount' => 'decimal:2',
        'winner_amount' => 'decimal:2',
        'published_at' => 'datetime',
        'questions_deadline_at' => 'datetime',
        'submission_deadline_at' => 'datetime',
        'submitted_at' => 'datetime',
        'opening_at' => 'datetime',
        'auction_at' => 'datetime',
        'result_expected_at' => 'datetime',
        'result_published_at' => 'datetime',
        'next_deadline_at' => 'datetime',
        'decided_at' => 'datetime',
        'requirements' => 'array',
        'evaluation_criteria' => 'array',
        'metadata' => 'array',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(TenderSource::class, 'source_id');
    }

    public function customerCompany(): BelongsTo
    {
        return $this->belongsTo(CrmCompany::class, 'customer_company_id');
    }

    public function customerContact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'customer_contact_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function crmDeal(): BelongsTo
    {
        return $this->belongsTo(CrmDeal::class, 'crm_deal_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function deadlines(): HasMany
    {
        return $this->hasMany(TenderDeadline::class);
    }

    public function requirementsList(): HasMany
    {
        return $this->hasMany(TenderRequirement::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(TenderFile::class);
    }

    public function risks(): HasMany
    {
        return $this->hasMany(TenderRisk::class);
    }

    public function competitors(): HasMany
    {
        return $this->hasMany(TenderCompetitor::class);
    }

    public function timeline(): HasMany
    {
        return $this->hasMany(TenderTimelineEvent::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['won', 'lost', 'cancelled']);
    }
}
