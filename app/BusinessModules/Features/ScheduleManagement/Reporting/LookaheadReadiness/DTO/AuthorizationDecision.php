<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\LookaheadReadinessCanonicalJson;
use InvalidArgumentException;

final readonly class AuthorizationDecision
{
    public string $decisionHash;

    public function __construct(
        public int $actorId,
        public string $permission,
        public int $organizationId,
        public int $projectId,
        public string $roleRevision,
        public string $grantRevision,
        public array $roleSlugs,
        public string $decidedAtUtc,
        public array $contextFactors = [],
        public array $grantingAssignments = [],
    ) {
        if ($actorId <= 0
            || $organizationId <= 0
            || $projectId < 0
            || $permission === ''
            || preg_match('/^[a-f0-9]{64}$/D', $roleRevision) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $grantRevision) !== 1
            || $decidedAtUtc === '') {
            throw new InvalidArgumentException('lookahead_readiness_authorization_decision_invalid');
        }

        $grantRoleSlugs = array_values(array_unique(array_filter(
            array_column($grantingAssignments, 'role_slug'),
            'is_string',
        )));
        sort($grantRoleSlugs, SORT_STRING);
        $declaredRoleSlugs = array_values(array_unique($roleSlugs));
        sort($declaredRoleSlugs, SORT_STRING);
        $grantEvidenceValid = $grantingAssignments !== [];
        foreach ($grantingAssignments as $grant) {
            if (! is_array($grant)) {
                $grantEvidenceValid = false;
                break;
            }
            $definition = $grant['role_definition'] ?? null;
            $matchedPermission = $grant['matched_permission'] ?? null;
            if (! is_array($definition)
                || ! is_string($matchedPermission)
                || ! self::permissionMatches($matchedPermission, $permission)
                || ($grant['role_definition_hash'] ?? null) !== LookaheadReadinessCanonicalJson::hash($definition)
                || ! is_string($grant['conditions_hash'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $grant['conditions_hash']) !== 1) {
                $grantEvidenceValid = false;
                break;
            }
        }
        $organizationMembership = $contextFactors['organization_membership'] ?? null;
        $projectMembership = $contextFactors['project_membership'] ?? null;
        $accessMode = is_array($organizationMembership)
            ? ($organizationMembership['project_access_mode'] ?? null)
            : null;
        if (! $grantEvidenceValid
            || $grantRoleSlugs !== $declaredRoleSlugs
            || ! hash_equals(LookaheadReadinessCanonicalJson::hash($grantingAssignments), $roleRevision)
            || ! hash_equals(LookaheadReadinessCanonicalJson::hash([
                'context_factors' => $contextFactors,
                'granting_assignments' => $grantingAssignments,
                'permission' => $permission,
            ]), $grantRevision)
            || ! is_array($organizationMembership)
            || ($organizationMembership['organization_id'] ?? null) !== (string) $organizationId
            || ! in_array($accessMode, ['all_projects', 'assigned_projects'], true)
            || ($accessMode === 'assigned_projects' && $projectId > 0 && (
                ! is_array($projectMembership)
                || ($projectMembership['project_id'] ?? null) !== (string) $projectId
                || ($projectMembership['user_id'] ?? null) !== (string) $actorId
                || ($projectMembership['is_active'] ?? null) !== true
            ))) {
            throw new InvalidArgumentException('lookahead_readiness_authorization_decision_invalid');
        }

        $this->decisionHash = LookaheadReadinessCanonicalJson::hash($this->canonicalSnapshot());
    }

    private static function permissionMatches(string $grant, string $permission): bool
    {
        return $grant === '*'
            || $grant === $permission
            || (str_ends_with($grant, '*') && str_starts_with($permission, substr($grant, 0, -1)));
    }

    public function canonicalSnapshot(): array
    {
        $roles = array_values(array_unique($this->roleSlugs));
        sort($roles, SORT_STRING);

        return [
            'actor_id' => (string) $this->actorId,
            'decided_at_utc' => $this->decidedAtUtc,
            'grant_revision' => $this->grantRevision,
            'granting_assignments' => $this->grantingAssignments,
            'organization_id' => (string) $this->organizationId,
            'permission' => $this->permission,
            'project_id' => (string) $this->projectId,
            'role_revision' => $this->roleRevision,
            'role_slugs' => $roles,
            'context_factors' => $this->contextFactors,
        ];
    }
}
