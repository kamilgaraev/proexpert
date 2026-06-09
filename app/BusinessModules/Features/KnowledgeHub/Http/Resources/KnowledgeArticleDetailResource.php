<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Http\Resources;

use App\BusinessModules\Features\KnowledgeHub\Services\KnowledgeHubContentSanitizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class KnowledgeArticleDetailResource extends JsonResource
{
    /**
     * @param Collection<int, mixed>|null $related
     */
    public function __construct($resource, private readonly ?Collection $related = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $base = (new KnowledgeArticleListResource($this->resource))->toArray($request);
        $content = $this->content !== null
            ? KnowledgeHubContentSanitizer::clean((string) $this->content)
            : null;

        return array_merge($base, [
            'content' => $content,
            'table_of_contents' => $this->tableOfContents((string) $content),
            'related' => KnowledgeArticleListResource::collection($this->related ?? collect())->resolve($request),
        ]);
    }

    /**
     * @return list<array{level: int, title: string, anchor: string}>
     */
    private function tableOfContents(string $content): array
    {
        if ($content === '') {
            return [];
        }

        preg_match_all('/<h([2-3])(?:\s[^>]*)?>(.*?)<\/h\1>/iu', $content, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->map(function (array $match, int $index): array {
                $title = trim(html_entity_decode(strip_tags((string) ($match[2] ?? ''))));
                $anchor = Str::slug($title);

                return [
                    'level' => (int) ($match[1] ?? 2),
                    'title' => $title,
                    'anchor' => $anchor !== '' ? $anchor : 'section-'.($index + 1),
                ];
            })
            ->filter(fn (array $item): bool => $item['title'] !== '')
            ->values()
            ->all();
    }
}
