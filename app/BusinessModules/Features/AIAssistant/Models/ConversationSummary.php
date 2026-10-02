<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationSummary extends Model
{
    protected $table = 'ai_conversation_summaries';

    protected $fillable = ['conversation_id', 'summary', 'summary_segments', 'selected_entities', 'user_decisions', 'source_refs', 'context_version'];

    protected $casts = ['summary_segments' => 'array', 'selected_entities' => 'array', 'user_decisions' => 'array', 'source_refs' => 'array'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
