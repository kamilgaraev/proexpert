<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class CrmLead extends CrmModel
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'company_id',
        'contact_id',
        'owner_user_id',
        'source_id',
        'converted_deal_id',
        'source_ref_type',
        'source_ref_id',
        'title',
        'status',
        'priority',
        'estimated_amount',
        'expected_start_date',
        'need_description',
        'utm',
        'raw_source_data',
        'lost_reason',
        'converted_at',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'estimated_amount' => 'decimal:2',
        'expected_start_date' => 'date',
        'utm' => 'array',
        'raw_source_data' => 'array',
        'converted_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(CrmCompany::class, 'company_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'contact_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(CrmSource::class, 'source_id');
    }

    public function convertedDeal(): BelongsTo
    {
        return $this->belongsTo(CrmDeal::class, 'converted_deal_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CrmActivity::class, 'lead_id');
    }
}
