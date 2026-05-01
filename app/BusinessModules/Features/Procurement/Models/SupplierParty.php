<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Models;

use App\BusinessModules\Features\Procurement\Enums\SupplierPartyStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierPartyTypeEnum;
use App\Models\Organization;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierParty extends Model
{
    protected $table = 'supplier_parties';

    protected $fillable = [
        'organization_id',
        'type',
        'status',
        'registered_supplier_id',
        'external_supplier_contact_id',
        'display_name',
        'contact_name',
        'email',
        'normalized_email',
        'phone',
        'tax_id',
        'snapshot',
        'linked_at',
    ];

    protected $casts = [
        'type' => SupplierPartyTypeEnum::class,
        'status' => SupplierPartyStatusEnum::class,
        'snapshot' => 'array',
        'linked_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'draft',
        'snapshot' => '{}',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function registeredSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'registered_supplier_id');
    }

    public function externalSupplierContact(): BelongsTo
    {
        return $this->belongsTo(ExternalSupplierContact::class);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeExternal(Builder $query): Builder
    {
        return $query->where('type', SupplierPartyTypeEnum::EXTERNAL->value);
    }

    public function scopeRegistered(Builder $query): Builder
    {
        return $query->where('type', SupplierPartyTypeEnum::REGISTERED->value);
    }
}
