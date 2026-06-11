<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Models;

use App\Models\Contract;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class CrmDeal extends CrmModel
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'company_id',
        'primary_contact_id',
        'lead_id',
        'owner_user_id',
        'project_id',
        'contract_id',
        'pipeline_id',
        'stage_id',
        'source_id',
        'title',
        'pipeline_code',
        'stage_code',
        'status',
        'amount',
        'currency',
        'probability',
        'expected_close_at',
        'won_at',
        'lost_at',
        'lost_reason',
        'next_activity_at',
        'custom_fields',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expected_close_at' => 'date',
        'won_at' => 'datetime',
        'lost_at' => 'datetime',
        'next_activity_at' => 'datetime',
        'custom_fields' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(CrmCompany::class, 'company_id');
    }

    public function primaryContact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'primary_contact_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(CrmPipeline::class, 'pipeline_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(CrmPipelineStage::class, 'stage_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(CrmSource::class, 'source_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CrmActivity::class, 'deal_id');
    }

}
