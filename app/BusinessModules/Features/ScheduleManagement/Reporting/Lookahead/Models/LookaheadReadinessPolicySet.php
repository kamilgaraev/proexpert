<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models;

use InvalidArgumentException;

final readonly class LookaheadReadinessPolicySet
{
    private array $policiesByProject;

    public function __construct(array $policies, array $projectIds)
    {
        if (! array_is_list($policies) || ! array_is_list($projectIds) || $projectIds === []) {
            throw new InvalidArgumentException('lookahead_policy_set_invalid');
        }

        $default = null;
        $specific = [];
        foreach ($policies as $policy) {
            if (! $policy instanceof LookaheadReadinessPolicyVersion) {
                throw new InvalidArgumentException('lookahead_policy_set_invalid');
            }
            if ($policy->projectId === null) {
                $default ??= $policy;

                continue;
            }
            $specific[$policy->projectId] ??= $policy;
        }

        $resolved = [];
        foreach ($projectIds as $projectId) {
            if (! is_int($projectId) || $projectId < 1 || isset($resolved[$projectId])) {
                throw new InvalidArgumentException('lookahead_policy_set_invalid');
            }
            $policy = $specific[$projectId] ?? $default;
            if (! $policy instanceof LookaheadReadinessPolicyVersion) {
                throw new InvalidArgumentException('lookahead_project_policy_unavailable');
            }
            $resolved[$projectId] = $policy;
        }
        ksort($resolved, SORT_NUMERIC);
        $this->policiesByProject = $resolved;
    }

    public function forProject(int $projectId): LookaheadReadinessPolicyVersion
    {
        return $this->policiesByProject[$projectId]
            ?? throw new InvalidArgumentException('lookahead_project_policy_unavailable');
    }

    public function all(): array
    {
        return $this->policiesByProject;
    }
}
