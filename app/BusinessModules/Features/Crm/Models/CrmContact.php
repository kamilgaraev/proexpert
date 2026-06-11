<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class CrmContact extends CrmModel
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'company_id',
        'owner_user_id',
        'source_id',
        'merged_into_id',
        'source_ref_type',
        'source_ref_id',
        'full_name',
        'position',
        'phone',
        'email',
        'messengers',
        'is_primary',
        'status',
        'personal_data_consent_at',
        'notes',
        'last_activity_at',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'messengers' => 'array',
        'is_primary' => 'boolean',
        'personal_data_consent_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(CrmCompany::class, 'company_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(CrmSource::class, 'source_id');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function contactPoints(): HasMany
    {
        return $this->hasMany(CrmContactPoint::class, 'contact_id');
    }

    public function identities(): HasMany
    {
        return $this->hasMany(CrmContactIdentity::class, 'contact_id');
    }

}
