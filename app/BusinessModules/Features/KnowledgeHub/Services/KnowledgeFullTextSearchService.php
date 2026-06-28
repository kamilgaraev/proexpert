<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class KnowledgeFullTextSearchService
{
    public function apply(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        if (! $this->supportsPostgresFullText()) {
            return $query->search($term);
        }

        $tsQuery = "websearch_to_tsquery('russian', ?)";

        return $query
            ->select('knowledge_articles.*')
            ->selectRaw("ts_rank_cd(knowledge_articles.search_vector, {$tsQuery}, 32) AS search_rank", [$term])
            ->selectRaw(
                "ts_headline('russian', COALESCE(knowledge_articles.content_plain_text, knowledge_articles.excerpt, knowledge_articles.title, ''), {$tsQuery}, 'StartSel=<mark>, StopSel=</mark>, MaxWords=28, MinWords=8') AS search_snippet",
                [$term],
            )
            ->whereRaw("knowledge_articles.search_vector @@ {$tsQuery}", [$term])
            ->orderByDesc('search_rank');
    }

    public function supportsPostgresFullText(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql'
            && Schema::hasColumn('knowledge_articles', 'search_vector');
    }
}
