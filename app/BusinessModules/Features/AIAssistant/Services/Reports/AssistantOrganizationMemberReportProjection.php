<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Domain\Authorization\Models\OrganizationCustomRole;
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
        $assignments = UserRoleAssignment::query()->availableInOrganization($organizationId)
            ->whereColumn('user_role_assignments.user_id', 'users.id');
        $priority = 'CASE';
        $priorityBindings = [];
        foreach ($this->labels->primaryRolePriorities() as $slug => $rank) {
            $priority .= ' WHEN user_role_assignments.role_type = ? AND user_role_assignments.role_slug = ? THEN '.$rank;
            array_push($priorityBindings, UserRoleAssignment::TYPE_SYSTEM, $slug);
        }
        $priority = $priorityBindings === [] ? '100000' : $priority.' ELSE 100000 END';
        $primary = (clone $assignments)->select('user_role_assignments.role_slug')
            ->orderByRaw($priority, $priorityBindings)->orderBy('user_role_assignments.id')->limit(1);
        [$labelsSql, $bindings] = $this->labelExpression($organizationId);
        $primaryLabel = (clone $primary)->select([])->selectRaw($labelsSql, $bindings);
        $distinctRoles = (clone $assignments)->select(['user_role_assignments.role_type', 'user_role_assignments.role_slug',
            'user_role_assignments.custom_role_id'])->selectRaw($labelsSql.' AS role_label', $bindings)->distinct();
        $roleNames = DB::query()->fromSub($distinctRoles, 'report_member_roles')
            ->selectRaw("STRING_AGG(report_member_roles.role_label, ', ' ORDER BY report_member_roles.role_label, report_member_roles.role_type, report_member_roles.role_slug, report_member_roles.custom_role_id)");
        $members = User::query()->join('organization_user AS report_membership', static function ($join) use ($organizationId): void {
            $join->on('report_membership.user_id', '=', 'users.id')->where('report_membership.organization_id', $organizationId);
        })->select(['users.id', 'users.name', 'users.email', 'users.position'])
            ->selectRaw('COALESCE(users.is_active, FALSE) AND COALESCE(report_membership.is_active, FALSE) AS is_active')
            ->selectSub($primary, 'primary_role')->selectSub($roleNames, 'roles')
            ->selectRaw('COALESCE(('.$primaryLabel->toSql().'), ?) AS primary_role_label',
                [...$primaryLabel->getBindings(), trans_message('user.role_missing')]);

        return $members->getModel()->newModelQuery()->fromSub($members, 'users');
    }

    public function fields(): array
    {
        return ['position' => ['type' => 'string', 'label' => trans_message('ai_custom_reports.member_position')],
            'is_active' => ['type' => 'boolean', 'label' => trans_message('ai_custom_reports.member_active')],
            'roles' => ['type' => 'string', 'label' => trans_message('ai_custom_reports.member_roles')],
            'primary_role' => ['type' => 'string', 'label' => trans_message('ai_custom_reports.member_role_code')],
            'primary_role_label' => ['type' => 'string', 'label' => trans_message('ai_custom_reports.member_primary_role')]];
    }

    private function labelExpression(int $organizationId): array
    {
        $customName = OrganizationCustomRole::query()->where('organization_id', $organizationId)->where('is_active', true)
            ->whereColumn('organization_custom_roles.id', 'user_role_assignments.custom_role_id')
            ->whereColumn('organization_custom_roles.slug', 'user_role_assignments.role_slug')->select('name')->limit(1);
        $sql = 'CASE';
        $bindings = [];
        foreach ($this->roles->getAllRoles()->keys() as $slug) {
            $sql .= ' WHEN user_role_assignments.role_slug = ? THEN ?';
            $bindings[] = $slug;
            $bindings[] = $this->labels->labelFor($slug);
        }

        $systemSql = $bindings === [] ? '?' : $sql.' ELSE ? END';

        return ['CASE WHEN user_role_assignments.role_type = ? THEN ('.$customName->toSql().') ELSE '.$systemSql.' END',
            [UserRoleAssignment::TYPE_CUSTOM, ...$customName->getBindings(), ...$bindings, trans_message('user.role_unknown')]];
    }
}
