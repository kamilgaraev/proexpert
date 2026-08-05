<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Access;

use App\BusinessModules\Core\Reporting\Application\Access\CurrentReportAuthorizationFacts;
use App\BusinessModules\Core\Reporting\Application\Access\CurrentReportPermissionDecision;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\CurrentReportAbacEvaluator;
use App\Domain\Authorization\Enums\ConditionType;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\RoleCondition;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\ValueObjects\ModulePermissionAliases;
use App\Domain\Authorization\ValueObjects\PermissionSet;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class LaravelCurrentReportAbacEvaluator implements CurrentReportAbacEvaluator
{
    public function evaluate(
        int $actorId,
        string $permission,
        CurrentReportAuthorizationFacts $facts,
    ): CurrentReportPermissionDecision {
        $granted = false;

        try {
            if (DB::transactionLevel() < 1 || $actorId !== $facts->actorId) {
                return $this->decision($actorId, $permission, $facts, false);
            }

            $assignments = UserRoleAssignment::query()
                ->with([
                    'context.parentContext',
                    'conditions' => static fn ($query) => $query->where('is_active', true),
                ])
                ->where('user_id', $actorId)
                ->where('is_active', true)
                ->where(static function ($query) use ($facts): void {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', $facts->occurredAt);
                })
                ->get();

            foreach ($assignments as $assignment) {
                try {
                    if (! $this->contextMatches($assignment, $facts)
                        || ! $this->roleGrants($assignment, $permission, $facts->organizationId)
                        || ! $this->conditionsPass($assignment->conditions->all(), $actorId, $facts->occurredAt)) {
                        continue;
                    }
                    $granted = true;
                    break;
                } catch (Throwable) {
                    continue;
                }
            }
        } catch (Throwable) {
            $granted = false;
        }

        return $this->decision($actorId, $permission, $facts, $granted);
    }

    private function contextMatches(UserRoleAssignment $assignment, CurrentReportAuthorizationFacts $facts): bool
    {
        $context = $assignment->context;
        if ($context === null) {
            return false;
        }

        return match ((string) $context->type) {
            'organization' => (int) $context->resource_id === $facts->organizationId,
            'project' => $facts->projectId !== null
                && (int) $context->resource_id === $facts->projectId
                && $context->parentContext !== null
                && (string) $context->parentContext->type === 'organization'
                && (int) $context->parentContext->resource_id === $facts->organizationId,
            default => false,
        };
    }

    private function roleGrants(UserRoleAssignment $assignment, string $permission, int $organizationId): bool
    {
        if ($assignment->role_type === UserRoleAssignment::TYPE_CUSTOM) {
            $role = OrganizationCustomRole::query()
                ->where('organization_id', $organizationId)
                ->where('slug', $assignment->role_slug)
                ->where('is_active', true)
                ->first();

            return $role instanceof OrganizationCustomRole && in_array($permission, $role->getAllPermissions(), true);
        }

        if ($assignment->role_type !== UserRoleAssignment::TYPE_SYSTEM) {
            return false;
        }

        foreach (glob(config_path('RoleDefinitions/*/'.basename((string) $assignment->role_slug).'.json')) ?: [] as $path) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (! is_array($decoded)) {
                continue;
            }
            if ($this->systemRoleGrants($decoded, $permission)) {
                return true;
            }
        }

        return false;
    }

    private function systemRoleGrants(array $role, string $permission): bool
    {
        $permissions = [];
        foreach ($role['system_permissions'] ?? [] as $systemPermission) {
            if (is_string($systemPermission)) {
                $permissions[] = $systemPermission;
            }
        }

        foreach ($role['module_permissions'] ?? [] as $module => $modulePermissions) {
            if (! is_string($module) || ! is_array($modulePermissions)) {
                continue;
            }

            foreach ($modulePermissions as $modulePermission) {
                if (! is_string($modulePermission)) {
                    continue;
                }

                $moduleVariants = ModulePermissionAliases::variants($module);
                if ($modulePermission === '*') {
                    foreach ($moduleVariants as $variant) {
                        $permissions[] = $variant.'.*';
                    }

                    continue;
                }

                $qualifiedForModule = false;
                foreach ($moduleVariants as $variant) {
                    if (str_starts_with($modulePermission, $variant.'.')) {
                        $qualifiedForModule = true;
                        break;
                    }
                }
                if ($qualifiedForModule) {
                    $permissions[] = $modulePermission;

                    continue;
                }

                $permissions[] = $module.'.'.$modulePermission;
            }
        }

        return (new PermissionSet($permissions))->hasSystemPermission($permission);
    }

    private function conditionsPass(array $conditions, int $actorId, DateTimeImmutable $occurredAt): bool
    {
        foreach ($conditions as $condition) {
            if (! $condition instanceof RoleCondition || ! $condition->is_active) {
                continue;
            }
            $type = $condition->condition_type;
            $data = $condition->condition_data;
            if (! $type instanceof ConditionType || ! is_array($data)) {
                return false;
            }
            if ($type === ConditionType::TIME && ! $this->timePasses($data, $occurredAt)) {
                return false;
            }
            if ($type === ConditionType::PROJECT_COUNT && ! $this->projectCountPasses($data, $actorId)) {
                return false;
            }
            if (! in_array($type, [ConditionType::TIME, ConditionType::PROJECT_COUNT], true)) {
                return false;
            }
        }

        return true;
    }

    private function timePasses(array $data, DateTimeImmutable $occurredAt): bool
    {
        try {
            if ($data === [] || array_diff(array_keys($data), ['valid_from', 'valid_until', 'working_days', 'working_hours']) !== []) {
                return false;
            }
            if (array_key_exists('valid_from', $data)
                && (! is_string($data['valid_from']) || trim($data['valid_from']) === '')) {
                return false;
            }
            if (array_key_exists('valid_until', $data)
                && (! is_string($data['valid_until']) || trim($data['valid_until']) === '')) {
                return false;
            }
            if (isset($data['valid_from']) && $occurredAt < new DateTimeImmutable($data['valid_from'])) {
                return false;
            }
            if (isset($data['valid_until']) && $occurredAt > new DateTimeImmutable($data['valid_until'])) {
                return false;
            }
            if (array_key_exists('working_days', $data)) {
                if (! is_array($data['working_days'])
                    || ! array_is_list($data['working_days'])
                    || $data['working_days'] === []) {
                    return false;
                }
                foreach ($data['working_days'] as $day) {
                    if (! is_int($day) || $day < 0 || $day > 6) {
                        return false;
                    }
                }
                if (! in_array((int) $occurredAt->format('w'), $data['working_days'], true)) {
                    return false;
                }
            }
            if (array_key_exists('working_hours', $data)) {
                if (! is_string($data['working_hours'])
                    || preg_match('/^((?:[01]\d|2[0-3]):[0-5]\d)-((?:[01]\d|2[0-3]):[0-5]\d)$/D', $data['working_hours'], $matches) !== 1
                    || $matches[1] > $matches[2]) {
                    return false;
                }
                $time = $occurredAt->format('H:i');
                if ($time < $matches[1] || $time > $matches[2]) {
                    return false;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    private function projectCountPasses(array $data, int $actorId): bool
    {
        if (array_keys($data) !== ['max_projects'] || ! is_int($data['max_projects']) || $data['max_projects'] < 1) {
            return false;
        }
        $count = DB::table('project_user')
            ->join('projects', 'projects.id', '=', 'project_user.project_id')
            ->where('project_user.user_id', $actorId)
            ->where('project_user.is_active', true)
            ->where('projects.status', 'active')
            ->where('projects.is_archived', false)
            ->whereNull('projects.deleted_at')
            ->count();

        return $count < $data['max_projects'];
    }

    private function decision(
        int $actorId,
        string $permission,
        CurrentReportAuthorizationFacts $facts,
        bool $granted,
    ): CurrentReportPermissionDecision {
        return new CurrentReportPermissionDecision(
            $actorId,
            $permission,
            $facts->organizationId,
            $facts->projectId,
            $facts->resource,
            $granted,
        );
    }
}
