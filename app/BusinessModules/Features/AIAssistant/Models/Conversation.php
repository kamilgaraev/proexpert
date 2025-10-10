<?php

namespace App\BusinessModules\Features\AIAssistant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Organization;
use App\Models\User;

class Conversation extends Model
{
    protected $table = 'ai_conversations';

    protected $fillable = [
        'organization_id',
        'user_id',
        'title',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function generateTitle(): void
    {
        if (!$this->title) {
            $firstMessage = $this->messages()->where('role', 'user')->first();
            if ($firstMessage) {
                $this->title = mb_substr($firstMessage->content, 0, 50) . '...';
                $this->save();
            }
        }
    }
}

