<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagSearchResult;
use DateTimeInterface;
use Throwable;

final class RagPromptContextBuilder
{
    /**
     * @param  array<int, RagSearchResult>  $results
     * @return array{prompt: string, metadata: array<string, mixed>}
     */
    public function build(string $query, array $results): array
    {
        $requested = $this->configInt('ai-assistant.rag.max_chunks', 8);
        $results = array_slice($results, 0, $requested);
        $sources = $this->sources($results);
        $used = $sources !== [];

        return [
            'prompt' => $used ? $this->prompt($sources) : '',
            'metadata' => [
                'enabled' => true,
                'used' => $used,
                'query' => $query,
                'sources' => $used ? $sources : [],
                'limits' => [
                    'requested' => $requested,
                    'returned' => $used ? count($sources) : 0,
                ],
            ],
        ];
    }

    /**
     * @param  array<int, RagSearchResult>  $results
     * @return array<int, array<string, mixed>>
     */
    private function sources(array $results): array
    {
        return array_values(array_map(static fn (RagSearchResult $result): array => [
            'source_type' => $result->sourceType,
            'entity_type' => $result->entityType,
            'entity_id' => $result->entityId,
            'project_id' => $result->projectId,
            'title' => $result->title,
            'excerpt' => $result->excerpt,
            'score' => round($result->similarity, 4),
            'updated_at' => $result->updatedAt?->format(DateTimeInterface::ATOM),
        ], $results));
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     */
    private function prompt(array $sources): string
    {
        $lines = ['ProHelper context:'];

        foreach ($sources as $index => $source) {
            $lines[] = sprintf(
                '[%d] %s: %s',
                $index + 1,
                (string) ($source['title'] ?? ''),
                (string) ($source['excerpt'] ?? '')
            );
        }

        return implode("\n", $lines);
    }

    private function configInt(string $key, int $default): int
    {
        try {
            $value = config($key, $default);
        } catch (Throwable) {
            return $default;
        }

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
