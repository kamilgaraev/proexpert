<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\RolePayloadFormatter;
use App\Domain\Authorization\Services\RoleScanner;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;

final class LegalWorkflowRoleOptions
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly RoleScanner $scanner,
        private readonly RolePayloadFormatter $formatter,
    ) {}

    public function forOrganization(User $actor, int $organizationId): array
    {
        if ((int) $actor->current_organization_id !== $organizationId
            || !$this->authorization->can($actor, 'legal_archive.settings.manage', ['organization_id' => $organizationId])) {
            throw new AuthorizationException;
        }
        $context = AuthorizationContext::findOrganizationContext($organizationId);
        $contexts = $context === null ? [] : AuthorizationContext::query()
            ->whereKey($context->id)->orWhere('parent_context_id', $context->id)->pluck('id')->all();
        $assignments = UserRoleAssignment::query()->active()->whereIn('context_id', $contexts)
            ->get(['user_id', 'role_slug'])->groupBy('user_id');
        $counts = [];
        User::query()->whereKey($assignments->keys()->all())
            ->whereExists(static function (Builder $membership) use ($organizationId): void {
                $membership->selectRaw('1')->from('organization_user')->whereColumn('organization_user.user_id', 'users.id')
                    ->where('organization_id', $organizationId)->where('is_active', true);
            })->lazyById(100)->each(function (User $member) use ($organizationId, $assignments, &$counts): void {
                $member->setAttribute('current_organization_id', $organizationId);
                if (!$this->authorization->can($member, 'legal_archive.view', ['organization_id' => $organizationId])
                    || !$this->authorization->can($member, LegalWorkflowPermissions::APPROVE, ['organization_id' => $organizationId])) {
                    return;
                }
                foreach ($assignments[$member->id]->pluck('role_slug')->unique() as $slug) {
                    $counts[$slug] = ($counts[$slug] ?? 0) + 1;
                }
            });
        $roles = [];
        foreach ($this->scanner->getAllRoles() as $slug => $definition) {
            if (!in_array($definition['context'] ?? null, ['organization', 'project'], true)) {
                continue;
            }
            $payload = $this->formatter->formatSystemRole($slug, $definition);
            $roles[] = ['slug' => $slug, 'name' => $payload['name'], 'active_assignees_count' => $counts[$slug] ?? 0];
        }
        foreach (OrganizationCustomRole::query()->forOrganization($organizationId)->active()->orderBy('name')->get(['slug', 'name']) as $role) {
            $roles[] = ['slug' => $role->slug, 'name' => $role->name, 'active_assignees_count' => $counts[$role->slug] ?? 0];
        }
        usort($roles, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));
        return ['roles' => $roles, 'can_create' => $this->authorization->can($actor, LegalWorkflowPermissions::MANAGE_TEMPLATES, ['organization_id' => $organizationId])];
    }
}
