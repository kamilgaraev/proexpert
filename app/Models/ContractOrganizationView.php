<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ContractOrganizationView extends Model
{
    protected $fillable = ['contract_id', 'organization_id', 'private_notes', 'visibility', 'access_revoked_at', 'version'];

    protected $casts = ['access_revoked_at' => 'datetime', 'version' => 'integer'];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class)->withTrashed();
    }
}
