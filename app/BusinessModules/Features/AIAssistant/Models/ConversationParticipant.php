<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationParticipant extends Model
{
    protected $table = 'ai_conversation_participants';

    protected $fillable = ['conversation_id', 'user_id', 'role', 'added_by_user_id'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
