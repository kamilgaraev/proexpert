<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Models;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SupplierProposalVersion extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'supplier_proposal_versions';

    protected $fillable = [
        'organization_id',
        'supplier_proposal_id',
        'supplier_party_id',
        'supplier_request_id',
        'version_number',
        'commercial_snapshot',
        'attachment_snapshot',
        'dimension_snapshot',
        'dimension_hash',
        'created_by',
    ];

    protected $casts = [
        'commercial_snapshot' => 'array',
        'attachment_snapshot' => 'array',
        'dimension_snapshot' => 'array',
    ];

    protected $attributes = [
        'commercial_snapshot' => '{}',
        'attachment_snapshot' => '{}',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Supplier proposal versions are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Supplier proposal versions are immutable.');
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function supplierProposal(): BelongsTo
    {
        return $this->belongsTo(SupplierProposal::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
