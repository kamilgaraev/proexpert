<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Presentation;

final class ProjectModelReviewCursorPaginator
{
    /** @param list<array<string,mixed>> $entities @param array<string,mixed> $filters @return array{entities:list<array<string,mixed>>,summary:array<string,int>,page:array<string,mixed>} */
    public function paginate(array $entities, array $filters): array
    {
        $filtered = array_values(array_filter($entities, static function (array $entity) use ($filters): bool {
            return (! isset($filters['status']) || $entity['status'] === $filters['status'])
                && (! array_key_exists('needs_action', $filters) || (bool) $entity['needs_action'] === (bool) $filters['needs_action']);
        }));
        usort($filtered, static fn (array $left, array $right): int => strcmp((string) $left['stable_key'], (string) $right['stable_key']));
        $summary = $this->summary($filtered);
        $cursor = $this->decode($filters['cursor'] ?? null);
        if ($cursor !== null) {
            $filtered = array_values(array_filter($filtered, static fn (array $entity): bool => strcmp((string) $entity['stable_key'], $cursor) > 0));
        }
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 50)));
        $slice = array_slice($filtered, 0, $perPage + 1);
        $hasMore = count($slice) > $perPage;
        $slice = array_slice($slice, 0, $perPage);

        return ['entities' => $slice, 'summary' => $summary, 'page' => [
            'per_page' => $perPage,
            'next_cursor' => $hasMore ? $this->encode((string) end($slice)['stable_key']) : null,
            'has_more' => $hasMore,
        ]];
    }

    /** @param list<array<string,mixed>> $entities @return array<string,int> */
    private function summary(array $entities): array
    {
        $counts = ['total' => count($entities), 'confirmed' => 0, 'needs_action' => 0, 'unconfirmed' => 0, 'conflict' => 0];
        foreach ($entities as $entity) {
            $counts[(string) $entity['status']]++;
        }
        $counts['actionable'] = $counts['needs_action'] + $counts['unconfirmed'] + $counts['conflict'];

        return $counts;
    }

    private function decode(mixed $raw): ?string
    {
        if (! is_string($raw) || $raw === '') return null;
        $decoded = base64_decode(strtr($raw, '-_', '+/'), true);
        $decoded = is_string($decoded) ? json_decode($decoded, true) : null;
        return is_array($decoded) && is_string($decoded['k'] ?? null) && preg_match('/^[a-z][a-z0-9:_-]{0,191}$/', $decoded['k']) === 1 ? $decoded['k'] : null;
    }

    private function encode(string $key): string
    {
        return rtrim(strtr(base64_encode(json_encode(['k' => $key], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
