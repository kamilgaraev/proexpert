<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Counterparty extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'linked_organization_id',
        'name',
        'legal_name',
        'inn',
        'kpp',
        'ogrn',
        'email',
        'phone',
        'contact_person',
        'legal_address',
        'postal_address',
        'bank_details',
        'roles',
        'source',
        'is_active',
    ];

    protected $casts = [
        'bank_details' => 'array',
        'roles' => 'array',
        'is_active' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function linkedOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'linked_organization_id');
    }

    public function contractParties(): HasMany
    {
        return $this->hasMany(ContractParty::class);
    }

    public function projectsAsCustomer(): HasMany
    {
        return $this->hasMany(Project::class, 'customer_counterparty_id');
    }
}
