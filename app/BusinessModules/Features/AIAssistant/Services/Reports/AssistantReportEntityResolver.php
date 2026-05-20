<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\Models\Organization;
use App\Models\User;

final readonly class AssistantReportEntityResolver
{
    private const TOOL_BY_ENTITY_TYPE = [
        'project' => 'search_projects',
        'warehouse' => 'search_warehouse',
        'contractor' => 'search_contractors',
        'user' => 'search_users',
    ];

    public function __construct(
        private AIToolRegistry $toolRegistry
    ) {}

    /**
     * @return array{
     *     status: 'resolved'|'ambiguous'|'not_found'|'unsupported',
     *     entity_type: string,
     *     query: string,
     *     entity?: array{id: int|string, label: string},
     *     candidates: array<int, array{id: int|string, label: string}>
     * }
     */
    public function resolve(
        string $entityType,
        string $query,
        ?User $user,
        Organization $organization,
        int $limit = 5
    ): array {
        $normalizedQuery = trim($query);
        $toolName = self::TOOL_BY_ENTITY_TYPE[$entityType] ?? null;

        if ($toolName === null || $normalizedQuery === '') {
            return $this->result('unsupported', $entityType, $normalizedQuery, []);
        }

        $tool = $this->toolRegistry->getTool($toolName);
        if ($tool === null) {
            return $this->result('unsupported', $entityType, $normalizedQuery, []);
        }

        $raw = $tool->execute([
            'query' => $normalizedQuery,
            'limit' => $limit,
        ], $user, $organization);

        $candidates = is_array($raw) ? $this->normalizeCandidates($raw['results'] ?? []) : [];

        if ($candidates === []) {
            return $this->result('not_found', $entityType, $normalizedQuery, []);
        }

        $exact = $this->exactMatches($normalizedQuery, $candidates);
        if (count($exact) === 1) {
            return $this->result('resolved', $entityType, $normalizedQuery, $candidates, $exact[0]);
        }

        if (count($candidates) === 1) {
            return $this->result('resolved', $entityType, $normalizedQuery, $candidates, $candidates[0]);
        }

        return $this->result('ambiguous', $entityType, $normalizedQuery, $candidates);
    }

    /**
     * @param  mixed  $results
     * @return array<int, array{id: int|string, label: string}>
     */
    private function normalizeCandidates(mixed $results): array
    {
        if (! is_iterable($results)) {
            return [];
        }

        $candidates = [];

        foreach ($results as $result) {
            if (! is_array($result) || ! array_key_exists('id', $result)) {
                continue;
            }

            $id = $result['id'];
            if (! is_int($id) && ! is_string($id)) {
                continue;
            }

            $label = $result['name'] ?? $result['title'] ?? $result['email'] ?? null;
            if (! is_string($label) && ! is_numeric($label)) {
                continue;
            }

            $candidates[] = [
                'id' => is_numeric($id) ? (int) $id : $id,
                'label' => trim((string) $label),
            ];
        }

        return array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => $candidate['label'] !== ''
        ));
    }

    /**
     * @param  array<int, array{id: int|string, label: string}>  $candidates
     * @return array<int, array{id: int|string, label: string}>
     */
    private function exactMatches(string $query, array $candidates): array
    {
        $normalizedQuery = $this->normalize($query);

        return array_values(array_filter(
            $candidates,
            fn (array $candidate): bool => $this->normalize($candidate['label']) === $normalizedQuery
        ));
    }

    /**
     * @param  array<int, array{id: int|string, label: string}>  $candidates
     * @param  array{id: int|string, label: string}|null  $entity
     * @return array{
     *     status: 'resolved'|'ambiguous'|'not_found'|'unsupported',
     *     entity_type: string,
     *     query: string,
     *     entity?: array{id: int|string, label: string},
     *     candidates: array<int, array{id: int|string, label: string}>
     * }
     */
    private function result(
        string $status,
        string $entityType,
        string $query,
        array $candidates,
        ?array $entity = null
    ): array {
        $result = [
            'status' => $status,
            'entity_type' => $entityType,
            'query' => $query,
            'candidates' => $candidates,
        ];

        if ($entity !== null) {
            $result['entity'] = $entity;
        }

        return $result;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace('ё', 'е', $value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;

        return preg_replace('/\s+/u', ' ', trim($value)) ?? '';
    }
}
