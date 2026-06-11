<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class CrmContactPoint extends CrmModel
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'company_id',
        'contact_id',
        'point_type',
        'label',
        'value',
        'normalized_value',
        'is_primary',
        'is_verified',
        'metadata',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_verified' => 'boolean',
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
