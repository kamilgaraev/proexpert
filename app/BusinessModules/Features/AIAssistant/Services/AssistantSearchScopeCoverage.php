<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\Models\User;

final class AssistantSearchScopeCoverage
{
    public static function references(array $attempts, array $projectIds, User $actor, int $organizationId): ?array
    {
        $required = array_fill_keys(['organization', ...array_map(static fn (int $id): string => 'project:'.$id, $projectIds)], true);
        $attempted = [];
        $confirmed = [];
        $groups = [];
        $references = [];
        foreach ($attempts as $attempt) {
            $arguments = $attempt['arguments'];
            $requested = self::specification($arguments);
            $side = self::side($arguments['project_id'] ?? null);
            $key = json_encode([$requested, $side], JSON_THROW_ON_ERROR);
            $attempted[$key] = true;
            $evidence = $attempt['evidence'] ?? null;
            if (! is_array($evidence) || ! AssistantDomainSearchCountEvidence::trusted($evidence)) {
                continue;
            }
            $reference = $evidence['source_ref'];
            $scope = $reference['_search_count_query'];
            $actual = self::specification($scope);
            $requestedCore = $requested;
            $actualCore = $actual;
            unset($requestedCore['fields'], $actualCore['fields']);
            if (($reference['organization_id'] ?? null) !== $organizationId
                || ($reference['_search_count_actor'] ?? null) !== (string) $actor->getKey()
                || self::side($scope['project_id'] ?? null) !== $side || ! isset($required[$side])
                || $requestedCore !== $actualCore
                || ($requested['fields'] !== null && $requested['fields'] !== $actual['fields'])) {
                continue;
            }
            $confirmed[$key] = true;
            $group = json_encode([$actual, $reference['_search_count_clock'] ?? null], JSON_THROW_ON_ERROR);
            $groups[$group][$side] = true;
            $references[$reference['entity_id'].':'.$reference['source_version']] = $reference;
        }
        if ($attempted === [] || array_diff_key($attempted, $confirmed) !== []) {
            return null;
        }
        foreach ($groups as $sides) {
            if (array_diff_key($required, $sides) !== []) {
                return null;
            }
        }

        return array_values($references);
    }

    private static function specification(array $arguments): array
    {
        $fields = $arguments['fields'] ?? null;
        if (is_array($fields)) {
            sort($fields);
        }

        return ['domain' => $arguments['domain'] ?? null, 'entity_type' => $arguments['entity_type'] ?? null,
            'query' => trim((string) ($arguments['query'] ?? '')), 'predicate' => $arguments['predicate'] ?? null,
            'fields' => $fields];
    }

    private static function side(mixed $projectId): string
    {
        return $projectId === null ? 'organization' : 'project:'.$projectId;
    }
}
