<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class CrmContactIdentity extends CrmModel
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'company_id',
        'contact_id',
        'identity_type',
        'value',
        'normalized_value',
        'source',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(CrmCompany::class, 'company_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'contact_id');
    }
}
