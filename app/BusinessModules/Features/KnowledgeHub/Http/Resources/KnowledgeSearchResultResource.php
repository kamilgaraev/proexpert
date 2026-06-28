<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Http\Resources;

use App\BusinessModules\Features\KnowledgeHub\Services\KnowledgeHubContentSanitizer;
use Illuminate\Http\Request;

class KnowledgeSearchResultResource extends KnowledgeArticleListResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $base = parent::toArray($request);
        $snippet = $this->search_snippet ?? $this->excerpt;

        return array_merge($base, [
            'search_rank' => $this->search_rank !== null ? (float) $this->search_rank : null,
            'snippet' => $snippet !== null
                ? KnowledgeHubContentSanitizer::clean((string) $snippet)
                : null,
        ]);
    }
}
