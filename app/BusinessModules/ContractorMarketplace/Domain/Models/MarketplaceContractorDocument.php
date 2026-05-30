<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceContractorDocument extends Model
{
    protected $table = 'marketplace_contractor_documents';

    protected $fillable = [
        'profile_id',
        'type',
        'title',
        'file_path',
        'status',
        'verified_at',
        'verified_by_user_id',
        'metadata',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketplaceContractorProfile::class, 'profile_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }
}
