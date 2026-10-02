<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantMemory extends Model
{
    use HasUuids;

    protected $table = 'ai_memories';

    protected $fillable = ['organization_id', 'user_id', 'created_by_user_id', 'conversation_id', 'kind', 'payload', 'source_refs', 'confirmed', 'version', 'last_used_at', 'expires_at'];

    protected $casts = ['payload' => 'array', 'source_refs' => 'array', 'confirmed' => 'boolean', 'version' => 'integer', 'last_used_at' => 'datetime', 'expires_at' => 'datetime'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
