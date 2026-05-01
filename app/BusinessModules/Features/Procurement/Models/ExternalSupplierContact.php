<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Models;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExternalSupplierContact extends Model
{
    use SoftDeletes;

    protected $table = 'external_supplier_contacts';

    protected $fillable = [
        'organization_id',
        'name',
        'contact_person',
        'phone',
        'email',
        'tax_number',
        'address',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function supplierRequests(): HasMany
    {
        return $this->hasMany(SupplierRequest::class);
    }
}
