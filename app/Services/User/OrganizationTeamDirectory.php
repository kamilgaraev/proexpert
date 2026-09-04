<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\RolePayloadFormatter;
use App\Domain\Authorization\Services\RoleScanner;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class OrganizationTeamDirectory
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly RoleScanner $roles,
        private readonly RolePayloadFormatter $roleFormatter,
    ) {}

    public function paginate(User $actor, int $organizationId, string $search = '', int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        if ($organizationId < 1 || ! $this->authorization->can($actor, 'users.manage', ['organization_id' => $organizationId])) {
            throw new AuthorizationException;
        }

        $query = User::query()
            ->select(['id', 'name', 'email', 'email_verified_at', 'is_active', 'created_at'])
            ->whereHas('organizations', fn (Builder $organizations) => $organizations
                ->where('organization_user.organization_id', $organizationId))
            ->with([
                'organizations' => fn ($organizations) => $organizations
                    ->select('organizations.id')
                    ->where('organizations.id', $organizationId),
                'roleAssignments' => fn ($assignments) => $assignments
                    ->active()
                    ->whereHas('context', fn (Builder $contexts) => $contexts
                        ->where('type', AuthorizationContext::TYPE_ORGANIZATION)
                        ->where('resource_id', $organizationId)),
            ]);

        $search = mb_substr(trim($search), 0, 200);
        if ($search !== '') {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(fn (Builder $users) => $users
                ->where('name', 'ilike', $pattern)
                ->orWhere('email', 'ilike', $pattern));
        }

        $users = $query->orderBy('name')->orderBy('id')
            ->paginate(min(100, max(1, $perPage)), ['*'], 'page', max(1, $page));
        $customSlugs = $users->getCollection()
            ->flatMap(fn (User $user) => $user->roleAssignments)
            ->where('role_type', UserRoleAssignment::TYPE_CUSTOM)
            ->pluck('role_slug')->unique()->values()->all();
        $customRoles = $customSlugs === [] ? collect() : OrganizationCustomRole::query()
            ->where('organization_id', $organizationId)
            ->whereIn('slug', $customSlugs)
            ->get(['id', 'slug', 'name'])
            ->keyBy('slug');

        $users->setCollection($users->getCollection()->map(function (User $user) use ($customRoles): array {
            $roles = $user->roleAssignments->map(function (UserRoleAssignment $assignment) use ($customRoles): array {
                $slug = (string) $assignment->role_slug;
                $customRole = $assignment->role_type === UserRoleAssignment::TYPE_CUSTOM ? $customRoles->get($slug) : null;
                $definition = $assignment->role_type === UserRoleAssignment::TYPE_SYSTEM
                    ? $this->roles->getRole($slug)
                    : null;

                return [
                    'id' => $customRole?->id,
                    'slug' => $slug,
                    'name' => $customRole?->name ?? ($definition === null
                        ? trans_message('landing_users.team_role_unavailable')
                        : $this->roleFormatter->translatedRoleName($slug, $definition)),
                    'type' => $assignment->role_type,
                ];
            })->unique(fn (array $role): string => $role['type'].':'.$role['slug'])->values()->all();

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toISOString(),
                'is_active' => (bool) $user->is_active && (bool) $user->organizations->first()?->pivot?->is_active,
                'roles' => $roles,
                'created_at' => $user->created_at?->toISOString(),
            ];
        }));

        return $users;
    }
}
