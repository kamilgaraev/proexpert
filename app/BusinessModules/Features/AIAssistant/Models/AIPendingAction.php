<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $organization_id
 * @property int $actor_user_id
 * @property int|null $conversation_id
 * @property string $tool_name
 * @property array $arguments
 * @property array|null $entity_state
 * @property string|null $entity_state_hash
 * @property string $status
 * @property Carbon $expires_at
 * @property array|null $result
 */
class AIPendingAction extends Model
{
    protected $table = 'ai_pending_actions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'organization_id',
        'actor_user_id',
        'conversation_id',
        'origin_request_id',
        'origin_request_payload',
        'origin_request_hash',
        'binding_hash',
        'conversation_context_version',
        'tool_name',
        'arguments',
        'action_class',
        'entity_state',
        'entity_state_hash',
        'token_hash',
        'status',
        'result',
        'expires_at',
        'claimed_at',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'origin_request_payload' => 'array',
            'conversation_context_version' => 'integer',
            'entity_state' => 'array',
            'result' => 'array',
            'expires_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'executed_at' => 'immutable_datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
