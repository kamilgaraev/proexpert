<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeArticleFeedback extends Model
{
    use HasFactory;

    protected $table = 'knowledge_article_feedback';

    protected $fillable = [
        'article_id',
        'user_id',
        'organization_id',
        'surface',
        'context_key',
        'reaction',
        'comment',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(KnowledgeArticle::class, 'article_id');
    }
}
