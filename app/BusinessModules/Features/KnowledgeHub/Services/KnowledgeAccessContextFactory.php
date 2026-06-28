<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Services;

use App\BusinessModules\Features\KnowledgeHub\DTOs\KnowledgeAccessContext;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeAudience;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class KnowledgeAccessContextFactory
{
    public function fromRequest(Request $request, KnowledgeSurface $defaultSurface): KnowledgeAccessContext
    {
        $surface = KnowledgeSurface::tryFrom((string) $request->input('surface', $defaultSurface->value))
            ?? $defaultSurface;

        $user = $request->user();
        $permissionKeys = $this->permissionKeys($user);
        $moduleSlug = $this->cleanSlug($request->input('module_slug', $request->input('module')));
        $permissionKey = $this->cleanPermission($request->input('permission_key'));
        $contextKey = $this->cleanContextKey($request->input('context_key'));
        $moduleSlugs = $this->moduleSlugs($permissionKeys, $moduleSlug);

        return new KnowledgeAccessContext(
            surface: $surface,
            audiences: $this->audiences($user, $surface),
            permissionKeys: $permissionKeys,
            moduleSlugs: $moduleSlugs,
            moduleSlug: $moduleSlug,
            permissionKey: $permissionKey,
            contextKey: $contextKey,
            userId: $this->userId($user),
            organizationId: $this->organizationId($user),
        );
    }

    /**
     * @return list<string>
     */
    private function permissionKeys(?Authenticatable $user): array
    {
        if ($user === null || ! method_exists($user, 'getPermissions')) {
            return [];
        }

        try {
            $permissions = $user->getPermissions();
        } catch (Throwable) {
            return [];
        }

        return collect($permissions)
            ->filter(fn (mixed $permission): bool => is_string($permission) && trim($permission) !== '')
            ->map(fn (string $permission): string => trim($permission))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function audiences(?Authenticatable $user, KnowledgeSurface $surface): array
    {
        $audiences = [KnowledgeAudience::ALL->value];

        if ($surface === KnowledgeSurface::SUPERADMIN) {
            $audiences[] = KnowledgeAudience::SYSTEM_ADMIN->value;
        }

        if ($surface === KnowledgeSurface::ADMIN) {
            $audiences[] = KnowledgeAudience::ADMIN->value;
        }

        if ($user === null || ! method_exists($user, 'roleAssignments')) {
            return array_values(array_unique($audiences));
        }

        try {
            $roleSlugs = $user->roleAssignments()->active()->pluck('role_slug')->all();
        } catch (Throwable) {
            return array_values(array_unique($audiences));
        }

        foreach ($roleSlugs as $roleSlug) {
            if (! is_string($roleSlug)) {
                continue;
            }

            $normalized = Str::of($roleSlug)->lower()->replace('-', '_')->toString();

            match (true) {
                str_contains($normalized, 'owner') => $audiences[] = KnowledgeAudience::OWNER->value,
                str_contains($normalized, 'admin') => $audiences[] = KnowledgeAudience::ADMIN->value,
                str_contains($normalized, 'manager') || str_contains($normalized, 'project_manager') => $audiences[] = KnowledgeAudience::MANAGER->value,
                str_contains($normalized, 'foreman') || str_contains($normalized, 'master') => $audiences[] = KnowledgeAudience::FOREMAN->value,
                str_contains($normalized, 'worker') => $audiences[] = KnowledgeAudience::WORKER->value,
                str_contains($normalized, 'contractor') => $audiences[] = KnowledgeAudience::CONTRACTOR->value,
                str_contains($normalized, 'accountant') || str_contains($normalized, 'finance') => $audiences[] = KnowledgeAudience::ACCOUNTANT->value,
                default => null,
            };
        }

        return array_values(array_unique($audiences));
    }

    /**
     * @param list<string> $permissionKeys
     * @return list<string>
     */
    private function moduleSlugs(array $permissionKeys, ?string $requestedModule): array
    {
        $moduleSlugs = [];

        if ($requestedModule !== null) {
            $moduleSlugs[] = $requestedModule;
        }

        foreach ($permissionKeys as $permissionKey) {
            if (! str_contains($permissionKey, '.')) {
                continue;
            }

            $module = Str::before($permissionKey, '.');
            $moduleSlugs[] = $module;
            $moduleSlugs[] = str_replace('_', '-', $module);
        }

        return collect($moduleSlugs)
            ->filter(fn (?string $slug): bool => $slug !== null && $slug !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function cleanSlug(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return Str::of($value)->lower()->replace('_', '-')->toString();
    }

    private function cleanPermission(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function cleanContextKey(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function userId(?Authenticatable $user): ?int
    {
        $identifier = $user?->getAuthIdentifier();

        return is_numeric($identifier) ? (int) $identifier : null;
    }

    private function organizationId(?Authenticatable $user): ?int
    {
        if ($user === null) {
            return null;
        }

        try {
            $organizationId = $user->current_organization_id ?? null;
        } catch (Throwable) {
            return null;
        }

        return is_numeric($organizationId) ? (int) $organizationId : null;
    }
}
