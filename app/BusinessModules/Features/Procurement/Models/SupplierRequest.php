<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Models;

use App\BusinessModules\Features\Procurement\Enums\SupplierRequestStatusEnum;
use App\Models\Organization;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierRequest extends Model
{
    use SoftDeletes;

    protected $table = 'supplier_requests';

    protected $fillable = [
        'organization_id',
        'purchase_request_id',
        'supplier_id',
        'external_supplier_contact_id',
        'supplier_party_id',
        'supplier_snapshot',
        'request_number',
        'status',
        'sent_at',
        'responded_at',
        'cancelled_at',
        'comment',
        'metadata',
    ];

    protected $casts = [
        'status' => SupplierRequestStatusEnum::class,
        'sent_at' => 'datetime',
        'responded_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'supplier_snapshot' => 'array',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'status' => 'draft',
        'supplier_snapshot' => '{}',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function externalSupplierContact(): BelongsTo
    {
        return $this->belongsTo(ExternalSupplierContact::class);
    }

    public function supplierParty(): BelongsTo
    {
        return $this->belongsTo(SupplierParty::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierRequestLine::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(SupplierProposal::class);
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeWithStatus($query, string|SupplierRequestStatusEnum $status)
    {
        $value = $status instanceof SupplierRequestStatusEnum ? $status->value : $status;

        return $query->where('status', $value);
    }

    public function canBeSent(): bool
    {
        $status = $this->getAttribute('status');

        return $status instanceof SupplierRequestStatusEnum && $status->canBeSent();
    }

    public function canBeCancelled(): bool
    {
        $status = $this->getAttribute('status');

        return $status instanceof SupplierRequestStatusEnum && $status->canBeCancelled();
    }
}
