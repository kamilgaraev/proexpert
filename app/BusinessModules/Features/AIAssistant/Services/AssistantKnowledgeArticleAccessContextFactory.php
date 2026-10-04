<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\KnowledgeHub\DTOs\KnowledgeAccessContext;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use Illuminate\Support\Facades\DB;

final class AssistantKnowledgeArticleAccessContextFactory
{
    public function __construct(private readonly ?OrganizationEntitlementService $modules = null) {}

    public function build(User $user, int $organizationId, KnowledgeSurface $surface, AuthorizationService $authorization, callable $organizationContext): KnowledgeAccessContext
    {
        try {
            $rawPermissionKeys = $authorization->getUserPermissions($user);
        } catch (\Throwable) {
            $rawPermissionKeys = [];
        }
        $permissionKeys = collect($rawPermissionKeys)
            ->filter(static fn (mixed $permission): bool => is_string($permission) && trim($permission) !== '')
            ->map(static fn (string $permission): string => trim($permission))
            ->unique()
            ->values()
            ->all();
        $requiredPermissionKeys = DB::table('knowledge_articles as articles')
            ->crossJoin(DB::raw("LATERAL jsonb_array_elements(CASE WHEN jsonb_typeof(articles.permission_keys) = 'array' THEN articles.permission_keys ELSE '[]'::jsonb END) AS permission(value)"))
            ->where('articles.status', 'published')->whereRaw("jsonb_typeof(permission.value) = 'string'")
            ->selectRaw("DISTINCT permission.value #>> '{}' AS permission_key")->pluck('permission_key')->all();
        $permissionKeys = array_values(array_intersect($permissionKeys, $requiredPermissionKeys));
        $permissionKeys = array_values(array_filter($permissionKeys,
            fn (string $permission): bool => $authorization->canCurrent($user, $permission, ['organization_id' => $organizationId])));
        $moduleSlugs = ($this->modules ?? app(\App\Services\Entitlements\OrganizationEntitlementService::class))
            ->getEffectiveModules($organizationId)->pluck('slug')->all();
        $audiences = ['all'];
        if ($surface === KnowledgeSurface::ADMIN) { $audiences[] = 'admin'; }
        $authContext = $organizationContext();
        $roles = $authContext === null ? collect() : $authorization->getUserRoles($user, $authContext);
        foreach ($roles as $role) {
            $slug = str_replace('-', '_', strtolower($role->role_slug));
            $audience = match (true) {
                str_contains($slug, 'owner') => 'owner', str_contains($slug, 'admin') => 'admin',
                str_contains($slug, 'manager') => 'manager', str_contains($slug, 'foreman') || str_contains($slug, 'master') => 'foreman',
                str_contains($slug, 'worker') => 'worker', str_contains($slug, 'contractor') => 'contractor',
                str_contains($slug, 'accountant') || str_contains($slug, 'finance') => 'accountant', default => null,
            };
            if ($audience !== null) { $audiences[] = $audience; }
        }
        return new KnowledgeAccessContext(
            $surface, array_values(array_unique($audiences)), $permissionKeys, $moduleSlugs, null, null, null, (int) $user->id, $organizationId);
    }
}
