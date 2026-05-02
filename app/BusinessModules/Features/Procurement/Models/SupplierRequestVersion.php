<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Models;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierRequestVersion extends Model
{
    protected $table = 'supplier_request_versions';

    protected $fillable = [
        'organization_id',
        'supplier_request_id',
        'version_number',
        'request_snapshot',
        'line_snapshot',
        'supplier_snapshot',
        'sent_by',
        'sent_at',
    ];

    protected $casts = [
        'request_snapshot' => 'array',
        'line_snapshot' => 'array',
        'supplier_snapshot' => 'array',
        'sent_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function supplierRequest(): BelongsTo
    {
        return $this->belongsTo(SupplierRequest::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(SupplierProposal::class, 'supplier_request_version_id');
    }
}
