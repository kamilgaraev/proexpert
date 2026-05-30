<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceContractorRegion extends Model
{
    protected $table = 'marketplace_contractor_regions';

    protected $fillable = [
        'profile_id',
        'country',
        'region',
        'city',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketplaceContractorProfile::class, 'profile_id');
    }
}
