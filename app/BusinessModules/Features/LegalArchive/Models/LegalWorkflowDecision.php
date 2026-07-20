<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\LegalArchive\Models;

use App\Exceptions\ImmutableDataException;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LegalWorkflowDecision extends Model
{
    protected $fillable = [
        'organization_id', 'instance_id', 'step_id', 'document_id', 'document_version_id',
        'document_content_hash', 'actor_type', 'actor_user_id', 'action', 'comment', 'reason', 'from_status',
        'to_status', 'context', 'request_hash', 'idempotency_key', 'decided_at',
    ];

    protected $casts = ['context' => 'array', 'decided_at' => 'datetime'];

    protected static function booted(): void
    {
        self::updating(static fn (self $decision): never => throw new ImmutableDataException(self::class, 'update'));
        self::deleting(static fn (self $decision): never => throw new ImmutableDataException(self::class, 'delete'));
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(LegalWorkflowInstance::class, 'instance_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(LegalWorkflowStep::class, 'step_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
