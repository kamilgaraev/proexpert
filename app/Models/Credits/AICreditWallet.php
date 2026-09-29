<?php

declare(strict_types=1);

namespace App\Models\Credits;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AICreditWallet extends Model
{
    protected $table = 'ai_credit_wallets';

    protected $fillable = ['organization_id', 'balance_minor', 'reserved_minor'];

    protected $casts = ['balance_minor' => 'integer', 'reserved_minor' => 'integer'];

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }

    public function availableMinor(): int { return max(0, (int) $this->balance_minor - (int) $this->reserved_minor); }
}
