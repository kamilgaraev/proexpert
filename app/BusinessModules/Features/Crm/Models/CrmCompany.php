<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Models;

use App\Models\Contractor;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

final class CrmCompany extends CrmModel
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'owner_user_id',
        'linked_organization_id',
        'linked_contractor_id',
        'source_id',
        'merged_into_id',
        'source_ref_type',
        'source_ref_id',
        'name',
        'legal_name',
        'company_type',
        'roles',
        'status',
        'inn',
        'kpp',
        'ogrn',
        'phone',
        'email',
        'website',
        'legal_address',
        'actual_address',
        'tags',
        'custom_fields',
        'notes',
        'last_activity_at',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'roles' => 'array',
        'tags' => 'array',
        'custom_fields' => 'array',
        'last_activity_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(CrmSource::class, 'source_id');
    }

    public function linkedOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'linked_organization_id');
    }

    public function linkedContractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class, 'linked_contractor_id');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CrmContact::class, 'company_id');
    }

    public function primaryContact(): HasOne
    {
        return $this->hasOne(CrmContact::class, 'company_id')->where('is_primary', true);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(CrmLead::class, 'company_id');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(CrmDeal::class, 'company_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CrmActivity::class, 'company_id');
    }

    public function contactPoints(): HasMany
    {
        return $this->hasMany(CrmContactPoint::class, 'company_id');
    }

    public function identities(): HasMany
    {
        return $this->hasMany(CrmContactIdentity::class, 'company_id');
    }

}
