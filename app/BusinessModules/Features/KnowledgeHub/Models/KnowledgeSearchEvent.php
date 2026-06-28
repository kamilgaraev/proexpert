<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeSearchEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'organization_id',
        'clicked_article_id',
        'surface',
        'query',
        'module_slug',
        'context_key',
        'results_count',
    ];

    protected $casts = [
        'results_count' => 'integer',
    ];

    public function clickedArticle(): BelongsTo
    {
        return $this->belongsTo(KnowledgeArticle::class, 'clicked_article_id');
    }
}
