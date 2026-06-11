<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class CrmActivity extends CrmModel
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'owner_user_id',
        'company_id',
        'contact_id',
        'lead_id',
        'deal_id',
        'type',
        'direction',
        'status',
        'subject',
        'body',
        'due_at',
        'completed_at',
        'result',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(CrmCompany::class, 'company_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'contact_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(CrmDeal::class, 'deal_id');
    }
}
