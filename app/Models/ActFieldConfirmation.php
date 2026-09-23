<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class ActFieldConfirmation extends Model
{
    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Field confirmations are immutable.'));
        self::deleting(static fn (): never => throw new LogicException('Field confirmations are immutable.'));
    }

    protected $fillable = [
        'act_id',
        'organization_id',
        'user_id',
        'file_id',
        'idempotency_key',
        'signature_sha256',
        'confirmed_at',
    ];

    protected $casts = [
        'confirmed_at' => 'immutable_datetime',
    ];

    public function act(): BelongsTo
    {
        return $this->belongsTo(ContractPerformanceAct::class, 'act_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
