<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Egress;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\Services\Privacy\Gateway\Contracts\GatewayModelRequest;

final class OrdinaryAssistantSourceProjection
{
    public const VERSION = 'ordinary-project-sql/1';

    public const MIXED_VERSION = 'ordinary-project-sql-document/1';

    public const MIXED_V2_VERSION = 'ordinary-project-sql-document/2';

    public const TOOLS = ['get_project_attention', 'get_project_production_schedule_summary'];

    public static function toolsForPolicy(string $version): array
    {
        return match ($version) {
            self::VERSION => self::TOOLS,
            OrdinaryAssistantDocumentProjection::VERSION => [OrdinaryAssistantDocumentProjection::TOOL],
            self::MIXED_VERSION, self::MIXED_V2_VERSION => [...self::TOOLS, OrdinaryAssistantDocumentProjection::TOOL],
            default => [],
        };
    }

    public static function allowsTools(string $version, array $tools): bool
    {
        return $tools !== [] && count(array_filter($tools, 'is_string')) === count($tools)
            && array_diff($tools, self::toolsForPolicy($version)) === []
            && ($version !== self::MIXED_VERSION || (count($tools) === 2 && count(array_unique($tools)) === 2
                && in_array(OrdinaryAssistantDocumentProjection::TOOL, $tools, true)))
            && ($version !== self::MIXED_V2_VERSION || (count($tools) === 3 && count(array_unique($tools)) === 3));
    }

    public static function projectionVersionForTool(string $tool): ?string
    {
        return in_array($tool, self::TOOLS, true) ? self::VERSION
            : ($tool === OrdinaryAssistantDocumentProjection::TOOL ? OrdinaryAssistantDocumentProjection::VERSION : null);
    }

    public static function allowsSources(string $version, array $sources): bool
    {
        $tools = [];
        foreach ($sources as $source) {
            if (! is_array($source) || ! is_string($source['tool'] ?? null)
                || self::projectionVersionForTool($source['tool']) === null
                || ($source['projection_version'] ?? null) !== self::projectionVersionForTool($source['tool'])) {
                return false;
            }
            $tools[] = $source['tool'];
        }

        return self::allowsTools($version, $tools)
            && ($version !== OrdinaryAssistantDocumentProjection::VERSION || count($sources) === 1);
    }

    public function arguments(string $tool, array $arguments, int $projectId): array
    {
        if ($tool === OrdinaryAssistantDocumentProjection::TOOL) {
            return app(OrdinaryAssistantDocumentProjection::class)->arguments($arguments, $projectId);
        }
        if (! in_array($tool, self::TOOLS, true) || ($arguments['project_id'] ?? null) !== $projectId) {
            throw new AssistantEgressDenied('scope_mismatch');
        }
        if ($tool === 'get_project_attention') {
            if (array_diff(array_keys($arguments), ['project_id', 'focus', 'limit']) !== []
                || ! in_array($arguments['focus'] ?? null, ['quality', 'schedule'], true)
                || ! is_int($arguments['limit'] ?? null) || $arguments['limit'] < 1 || $arguments['limit'] > 20) {
                throw new AssistantEgressDenied('unsupported_purpose');
            }
        } else {
            if (array_diff(array_keys($arguments), ['project_id', 'period_start', 'period_end']) !== []) {
                throw new AssistantEgressDenied('unsupported_purpose');
            }
            foreach (['period_start', 'period_end'] as $key) {
                if (($arguments[$key] ?? null) === null) {
                    unset($arguments[$key]);

                    continue;
                }
                if (! is_string($arguments[$key]) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $arguments[$key]) !== 1) {
                    throw new AssistantEgressDenied('scope_mismatch');
                }
            }
        }
        ksort($arguments);

        return $arguments;
    }

    public function project(string $tool, array|string $result, int $organizationId, int $actorId, int $projectId): array
    {
        if (! is_array($result) || ! in_array($result['status'] ?? null, ['success', 'partial'], true)
            || ($result['data']['project']['id'] ?? null) !== $projectId || empty($result['source_refs'])) {
            throw new AssistantEgressDenied('source_unknown');
        }
        foreach ($result['source_refs'] as $ref) {
            if (! is_array($ref) || ($ref['project_id'] ?? null) !== $projectId || ($ref['organization_id'] ?? null) !== $organizationId
                || ($ref['actor_id'] ?? null) !== $actorId || ! is_string($ref['source_version'] ?? null)) {
                throw new AssistantEgressDenied('scope_mismatch');
            }
        }
        $data = $result['data'];
        $base = ['project' => ['id' => true, 'name' => true], 'as_of_date' => true];
        if ($tool === 'get_project_attention') {
            $dataSchema = $base + ['groups' => ['*' => ['kind' => true, 'total' => true, 'has_more' => true, 'title' => true,
                'items' => ['*' => ['entity_type' => true, 'id' => true, 'title' => true, 'status' => true, 'deadline' => true,
                    'owner_state' => true]]]], 'coverage' => array_fill_keys([
                        'critical_quality', 'overdue_quality', 'other_quality', 'overdue_schedule'], ['available' => true, 'label' => true, 'total' => true])];
        } elseif ($tool === 'get_project_production_schedule_summary') {
            $dataSchema = $base + array_fill_keys(['period_start', 'period_end', 'confirmed_works', 'volume_groups_limited',
                'total_schedules', 'active_schedules', 'total_tasks', 'active_work_tasks', 'open_tasks', 'overdue_tasks',
                'unassigned_open_tasks', 'project_end_on', 'active_schedule_end_on'], true) + [
                    'schedule_counts' => array_fill_keys(['draft', 'active', 'completed', 'cancelled', 'on_hold'], true),
                    'task_counts' => array_fill_keys(['not_started', 'in_progress', 'completed', 'cancelled', 'on_hold'], true),
                    'accepted_volumes' => ['*' => ['unit' => true, 'records' => true, 'quantity' => true]],
                    'examples' => ['*' => ['id' => true, 'name' => true, 'status' => true, 'planned_end_on' => true,
                        'owner_state' => true, 'is_overdue' => true]],
                ];
        } else {
            throw new AssistantEgressDenied('unsupported_purpose');
        }

        $selected = $this->select($data, $dataSchema);
        foreach ($selected['groups'] ?? [] as $groupIndex => $group) {
            foreach ($group['items'] ?? [] as $itemIndex => $item) {
                $selected['groups'][$groupIndex]['items'][$itemIndex]['assignment_state'] = ($item['owner_state'] ?? null) === 'unassigned' ? 'unassigned' : 'assigned';
                unset($selected['groups'][$groupIndex]['items'][$itemIndex]['owner_state']);
            }
        }
        foreach ($selected['examples'] ?? [] as $index => $item) {
            $selected['examples'][$index]['assignment_state'] = ($item['owner_state'] ?? null) === 'unassigned' ? 'unassigned' : 'assigned';
            unset($selected['examples'][$index]['owner_state']);
        }

        return ['status' => $result['status'], 'data' => $selected,
            'source_refs' => $result['source_refs'], 'egress_projection_version' => self::VERSION];
    }

    public function forProvider(array $projected): array
    {
        if (($projected['egress_projection_version'] ?? null) === OrdinaryAssistantDocumentProjection::VERSION) {
            return OrdinaryAssistantDocumentProjection::forProvider($projected);
        }
        $projected['source_refs'] = array_values(array_unique(array_map(static fn (array $ref): array => [
            'entity_type' => 'project', 'entity_id' => $ref['project_id'], 'project_id' => $ref['project_id'],
        ], $projected['source_refs']), SORT_REGULAR));

        return $projected;
    }

    public static function hash(array $value): string
    {
        return hash('sha256', GatewayModelRequest::canonicalJson($value));
    }

    private function select(array $value, array $schema): array
    {
        if (isset($schema['*'])) {
            if (! array_is_list($value)) {
                throw new AssistantEgressDenied('unknown_category');
            }

            return array_map(fn (mixed $item): array => is_array($item) ? $this->select($item, $schema['*'])
                : throw new AssistantEgressDenied('unknown_category'), $value);
        }
        $selected = [];
        foreach ($schema as $key => $shape) {
            if (! array_key_exists($key, $value)) {
                continue;
            }
            if ($shape === true) {
                if (! is_scalar($value[$key]) && $value[$key] !== null) {
                    throw new AssistantEgressDenied('unknown_category');
                }
                $selected[$key] = $value[$key];
            } elseif (is_array($value[$key])) {
                $selected[$key] = $this->select($value[$key], $shape);
            } else {
                throw new AssistantEgressDenied('unknown_category');
            }
        }

        return $selected;
    }
}
