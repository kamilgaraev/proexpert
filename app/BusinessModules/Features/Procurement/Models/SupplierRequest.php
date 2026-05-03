<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Models;

use App\BusinessModules\Features\Procurement\Enums\SupplierRequestStatusEnum;
use App\Models\Organization;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $purchase_request_id
 * @property int|null $supplier_id
 * @property int|null $external_supplier_contact_id
 * @property int|null $supplier_party_id
 * @property array<string, mixed>|null $supplier_snapshot
 * @property string $request_number
 * @property string|null $public_token
 * @property SupplierRequestStatusEnum $status
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $public_token_expires_at
 * @property \Illuminate\Support\Carbon|null $public_opened_at
 * @property \Illuminate\Support\Carbon|null $responded_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property string|null $comment
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
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
        'public_token',
        'status',
        'sent_at',
        'public_token_expires_at',
        'public_opened_at',
        'responded_at',
        'cancelled_at',
        'comment',
        'metadata',
    ];

    protected $casts = [
        'status' => SupplierRequestStatusEnum::class,
        'sent_at' => 'datetime',
        'public_token_expires_at' => 'datetime',
        'public_opened_at' => 'datetime',
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

    public function versions(): HasMany
    {
        return $this->hasMany(SupplierRequestVersion::class);
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(SupplierRequestVersion::class)->latestOfMany('version_number');
    }

    public function proposalDecision(): HasOne
    {
        return $this->hasOne(SupplierProposalDecision::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(ProcurementAuditEvent::class, 'subject_id')
            ->where('subject_type', $this->getMorphClass())
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    public function decision(): HasOne
    {
        return $this->proposalDecision();
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

    public function canReceivePublicProposal(): bool
    {
        return $this->status === SupplierRequestStatusEnum::SENT
            && $this->public_token !== null
            && (
                $this->public_token_expires_at === null
                || $this->public_token_expires_at->isFuture()
            );
    }

    public function publicUrl(): ?string
    {
        if ($this->public_token === null) {
            return null;
        }

        return rtrim((string) config('app.frontend_url'), '/') . '/supplier-requests/' . $this->public_token;
    }
}
