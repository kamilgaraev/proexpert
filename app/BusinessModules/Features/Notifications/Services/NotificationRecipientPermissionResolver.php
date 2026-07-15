<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Services;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;

final class NotificationRecipientPermissionResolver
{
    private const TYPE_PERMISSION_MAP = [
        'procurement' => 'notifications.receive.procurement',
        'site_requests' => 'notifications.receive.site_requests',
        'site_request' => 'notifications.receive.site_requests',
        'system' => 'notifications.receive.system',
    ];

    public function __construct(
        private readonly AuthorizationService $authorizationService,
    ) {}

    public function requiredPermissions(
        string $type,
        string $notificationType,
        array $data,
        string|array|null $explicitPermissions = null,
    ): array {
        if ($explicitPermissions !== null) {
            return $this->normalizePermissions($explicitPermissions);
        }

        $explicit = $this->normalizePermissions($data['required_permissions'] ?? $data['required_permission'] ?? null);

        if ($explicit !== []) {
            return $explicit;
        }

        foreach ($this->notificationKeys($type, $notificationType, $data) as $key) {
            $permission = $this->permissionForKey($key);

            if ($permission !== null) {
                return [$permission];
            }
        }

        return [];
    }

    public function canReceive(User $user, array $permissions, ?int $organizationId, array $data): bool
    {
        if ($permissions === []) {
            return true;
        }

        $context = $this->authorizationContext($user, $organizationId, $data);

        if ($context === []) {
            return false;
        }

        foreach ($permissions as $permission) {
            if ($this->authorizationService->can($user, $permission, $context)) {
                return true;
            }
        }

        return false;
    }

    public function authorizationContext(User $user, ?int $organizationId, array $data): array
    {
        $resolvedOrganizationId = $organizationId
            ?? $this->positiveInt($data['organization_id'] ?? null)
            ?? $this->positiveInt($user->current_organization_id ?? null);

        if ($resolvedOrganizationId === null) {
            return [];
        }

        $context = ['organization_id' => $resolvedOrganizationId];
        $projectId = $this->positiveInt($data['project_id'] ?? null);

        if ($projectId !== null) {
            $context['project_id'] = $projectId;
        }

        return $context;
    }

    private function normalizePermissions(mixed $permissions): array
    {
        if (is_string($permissions)) {
            $permissions = [$permissions];
        }

        if (! is_array($permissions)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $permission): ?string => is_string($permission) && trim($permission) !== ''
                    ? trim($permission)
                    : null,
                $permissions,
            ),
        )));
    }

    private function notificationKeys(string $type, string $notificationType, array $data): array
    {
        $keys = [
            $notificationType,
            $this->stringValue($data['notification_type'] ?? null),
            $this->stringValue($data['category'] ?? null),
            $this->stringValue($data['type'] ?? null),
            $type,
        ];

        return array_values(array_unique(array_filter($keys)));
    }

    private function permissionForKey(string $key): ?string
    {
        foreach (self::TYPE_PERMISSION_MAP as $prefix => $permission) {
            if ($key === $prefix || str_starts_with($key, "{$prefix}.") || str_starts_with($key, "{$prefix}_")) {
                return $permission;
            }
        }

        return null;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }
}
