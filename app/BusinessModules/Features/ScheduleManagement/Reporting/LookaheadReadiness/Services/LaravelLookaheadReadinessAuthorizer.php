<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Contracts\LookaheadReadinessAuthorizer;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\AuthorizationDecision;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\RoleCondition;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\RoleScanner;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class LaravelLookaheadReadinessAuthorizer implements LookaheadReadinessAuthorizer
{
    public function __construct(
        private RoleScanner $roleScanner,
    ) {}

    public function authorize(
        int $actorId,
        string $permission,
        int $organizationId,
        int $projectId,
    ): AuthorizationDecision {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('lookahead_readiness_owner_transaction_required');
        }

        $actor = User::query()
            ->whereKey($actorId)
            ->lockForUpdate()
            ->first();
        $membership = DB::table('organization_user')
            ->where('organization_id', $organizationId)
            ->where('user_id', $actorId)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first(['organization_id', 'project_access_mode', 'updated_at']);
        if ($membership === null
            || ! in_array($membership->project_access_mode, ['all_projects', 'assigned_projects'], true)) {
            throw new AuthorizationException(trans_message('permissions.unauthorized'));
        }
        $projectMembership = null;
        if ($projectId > 0 && $membership?->project_access_mode === 'assigned_projects') {
            $projectMembership = DB::table('project_user')
                ->where('project_id', $projectId)
                ->where('user_id', $actorId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first(['project_id', 'user_id', 'is_active', 'updated_at']);
        }

        $organizationContext = AuthorizationContext::findOrganizationContext($organizationId);
        $projectContext = $projectId > 0
            ? AuthorizationContext::findProjectContext($projectId, $organizationId)
            : null;
        $systemContext = AuthorizationContext::findSystemContext();
        $contextIds = array_values(array_filter([
            $systemContext?->id,
            $organizationContext?->id,
            $projectContext?->id,
        ]));
        $assignments = UserRoleAssignment::query()
            ->where('user_id', $actorId)
            ->whereIn('context_id', $contextIds)
            ->where('is_active', true)
            ->where(static function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $decisionContext = array_filter([
            'context_type' => $projectId > 0 ? 'project' : 'organization',
            'organization_id' => $organizationId,
            'project_id' => $projectId > 0 ? $projectId : null,
        ]);
        if ($actor === null
            || $membership === null
            || ($membership->project_access_mode === 'assigned_projects' && $projectId > 0 && $projectMembership === null)
            || $organizationContext === null
            || ($projectId > 0 && $projectContext === null)) {
            throw new AuthorizationException(trans_message('permissions.unauthorized'));
        }

        $grantingAssignments = [];
        foreach ($assignments as $assignment) {
            $conditions = RoleCondition::query()
                ->where('assignment_id', $assignment->id)
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $roleDefinition = $assignment->role_type === UserRoleAssignment::TYPE_SYSTEM
                ? $this->roleScanner->getRoleUncached($assignment->role_slug)
                : OrganizationCustomRole::query()
                    ->where('organization_id', $organizationId)
                    ->where('slug', $assignment->role_slug)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();
            $roleDefinitionArray = match (true) {
                $roleDefinition instanceof OrganizationCustomRole => [
                    'is_active' => (bool) $roleDefinition->is_active,
                    'module_permissions' => $roleDefinition->module_permissions ?? [],
                    'organization_id' => (string) $roleDefinition->organization_id,
                    'slug' => (string) $roleDefinition->slug,
                    'system_permissions' => $roleDefinition->system_permissions ?? [],
                    'updated_at' => (string) $roleDefinition->updated_at,
                ],
                is_array($roleDefinition) => $roleDefinition,
                default => [],
            };
            $matchedPermission = $this->matchedPermission($roleDefinitionArray, $permission);
            if ($matchedPermission === null || $conditions->isNotEmpty()) {
                continue;
            }
            $grantingAssignments[] = [
                'assignment_id' => (string) $assignment->id,
                'context_id' => (string) $assignment->context_id,
                'matched_permission' => $matchedPermission,
                'role_definition' => $roleDefinitionArray,
                'role_definition_hash' => LookaheadReadinessCanonicalJson::hash(
                    $roleDefinitionArray,
                ),
                'role_slug' => (string) $assignment->role_slug,
                'role_type' => (string) $assignment->role_type,
                'assignment_updated_at' => (string) $assignment->updated_at,
                'conditions_hash' => LookaheadReadinessCanonicalJson::hash(
                    $conditions->map(static fn (RoleCondition $condition): array => [
                        'condition_data' => $condition->condition_data,
                        'condition_type' => $condition->condition_type->value,
                        'id' => (string) $condition->id,
                        'updated_at' => (string) $condition->updated_at,
                    ])->all(),
                ),
            ];
        }
        if ($grantingAssignments === []) {
            throw new AuthorizationException(trans_message('permissions.unauthorized'));
        }

        $roleSlugs = array_values(array_unique(array_column($grantingAssignments, 'role_slug')));
        sort($roleSlugs, SORT_STRING);
        $contextFactors = [
            'organization_membership' => [
                'organization_id' => (string) $membership->organization_id,
                'project_access_mode' => (string) $membership->project_access_mode,
                'updated_at' => (string) $membership->updated_at,
            ],
            'project_membership' => $projectMembership === null ? null : [
                'is_active' => (bool) $projectMembership->is_active,
                'project_id' => (string) $projectMembership->project_id,
                'updated_at' => (string) $projectMembership->updated_at,
                'user_id' => (string) $projectMembership->user_id,
            ],
            'context_ids' => array_map('strval', $contextIds),
        ];
        $decidedAtUtc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s.u\Z');

        return new AuthorizationDecision(
            actorId: $actorId,
            permission: $permission,
            organizationId: $organizationId,
            projectId: $projectId,
            roleRevision: LookaheadReadinessCanonicalJson::hash($grantingAssignments),
            grantRevision: LookaheadReadinessCanonicalJson::hash([
                'context_factors' => $contextFactors,
                'granting_assignments' => $grantingAssignments,
                'permission' => $permission,
            ]),
            roleSlugs: $roleSlugs,
            decidedAtUtc: $decidedAtUtc,
            contextFactors: $contextFactors,
            grantingAssignments: $grantingAssignments,
        );
    }

    private function matchedPermission(array $roleDefinition, string $permission): ?string
    {
        $grants = array_values(array_filter(
            $roleDefinition['system_permissions'] ?? [],
            'is_string',
        ));
        foreach (($roleDefinition['module_permissions'] ?? []) as $module => $modulePermissions) {
            if (! is_string($module) || ! is_array($modulePermissions)) {
                continue;
            }
            foreach ($modulePermissions as $modulePermission) {
                if (is_string($modulePermission)) {
                    $grants[] = str_contains($modulePermission, '.')
                        ? $modulePermission
                        : $module.'.'.$modulePermission;
                }
            }
        }

        foreach ($grants as $grant) {
            if ($grant === '*'
                || $grant === $permission
                || (str_ends_with($grant, '*') && str_starts_with($permission, substr($grant, 0, -1)))) {
                return $grant;
            }
        }

        return null;
    }
}
