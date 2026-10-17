<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\RoleScanner;
use App\Models\User;
use App\Services\User\AdminUserRolePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class AssistantOrganizationMemberReportProjection
{
    public function __construct(private RoleScanner $roles, private AdminUserRolePolicy $labels) {}

    public function query(User $actor, int $organizationId, AssistantDataAccessPolicy $policy): Builder
    {
        if (! $policy->belongsToOrganization($actor, $organizationId)
            || ! $policy->canReadDomain($actor, $organizationId, 'people')
            || ! $policy->canCurrentPermission($actor, $organizationId, 'users.view')) {
            throw new AccessDeniedHttpException;
        }
        $assignments = UserRoleAssignment::query()->active()->whereColumn('user_role_assignments.user_id', 'users.id')
            ->whereHas('context', static fn (Builder $context): Builder => $context
                ->where('type', AuthorizationContext::TYPE_ORGANIZATION)->where('resource_id', $organizationId));
        $priority = 'CASE user_role_assignments.role_slug';
        foreach (AdminUserRolePolicy::ROLE_PRIORITY as $rank => $slug) {
            $priority .= ' WHEN ? THEN '.$rank;
        }
        $priority .= ' ELSE '.count(AdminUserRolePolicy::ROLE_PRIORITY).' END';
        $primary = (clone $assignments)->select('user_role_assignments.role_slug')
            ->orderByRaw($priority, AdminUserRolePolicy::ROLE_PRIORITY)->orderBy('user_role_assignments.id')->limit(1);
        [$labelsSql, $bindings] = $this->labelExpression('user_role_assignments.role_slug');
        $roleNames = DB::query()->fromSub((clone $assignments)->selectRaw($labelsSql.' AS role_label', $bindings), 'report_member_roles')
            ->selectRaw("STRING_AGG(DISTINCT report_member_roles.role_label, ', ' ORDER BY report_member_roles.role_label)");
        $members = User::query()->join('organization_user AS report_membership', static function ($join) use ($organizationId): void {
            $join->on('report_membership.user_id', '=', 'users.id')->where('report_membership.organization_id', $organizationId);
        })->select(['users.id', 'users.name', 'users.email', 'users.position'])
            ->selectRaw('COALESCE(users.is_active, FALSE) AND COALESCE(report_membership.is_active, FALSE) AS is_active')
            ->selectSub($primary, 'primary_role')->selectSub($roleNames, 'roles');
        [$primarySql, $primaryBindings] = $this->labelExpression('users.primary_role');
        $labeled = $members->getModel()->newModelQuery()->fromSub($members, 'users')->select('users.*')
            ->selectRaw('CASE WHEN users.primary_role IS NULL THEN ? ELSE '.$primarySql.' END AS primary_role_label',
                [trans_message('user.role_missing'), ...$primaryBindings]);

        return $members->getModel()->newModelQuery()->fromSub($labeled, 'users');
    }

    public function fields(): array
    {
        return ['position' => ['type' => 'string', 'label' => trans_message('ai_custom_reports.member_position')],
            'is_active' => ['type' => 'boolean', 'label' => trans_message('ai_custom_reports.member_active')],
            'roles' => ['type' => 'string', 'label' => trans_message('ai_custom_reports.member_roles')],
            'primary_role' => ['type' => 'string', 'label' => trans_message('ai_custom_reports.member_role_code')],
            'primary_role_label' => ['type' => 'string', 'label' => trans_message('ai_custom_reports.member_primary_role')]];
    }

    private function labelExpression(string $column): array
    {
        $sql = 'CASE '.$column;
        $bindings = [];
        foreach ($this->roles->getAllRoles()->keys() as $slug) {
            $sql .= ' WHEN ? THEN ?';
            $bindings[] = $slug;
            $bindings[] = $this->labels->labelFor($slug);
        }

        return [$sql.' ELSE ? END', [...$bindings, trans_message('user.role_unknown')]];
    }
}
