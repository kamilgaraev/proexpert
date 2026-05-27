<?php

declare(strict_types=1);

namespace App\Filament\Support\Concerns;

use App\Filament\Support\SystemAdminAccess;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;

trait AuthorizesSystemAdminResource
{
    public static function canViewAny(): bool
    {
        return static::allowsSystemAdminPolicy('viewAny');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return static::allowsSystemAdminPolicy('create');
    }

    public static function canEdit(Model $record): bool
    {
        return static::allowsSystemAdminPolicy('update', $record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::allowsSystemAdminPolicy('delete', $record);
    }

    public static function canDeleteAny(): bool
    {
        return static::allowsSystemAdminPolicy('deleteAny');
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canReorder(): bool
    {
        return false;
    }

    public static function canReplicate(Model $record): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }

    public static function canView(Model $record): bool
    {
        return static::allowsSystemAdminPolicy('view', $record);
    }

    protected static function allowsSystemAdminPolicy(string $method, ?Model $record = null): bool
    {
        $user = SystemAdminAccess::user();
        $policyClass = static::resolveSystemAdminPolicy();

        if ($user === null || $policyClass === null) {
            return false;
        }

        $policy = app($policyClass);

        if (! method_exists($policy, $method)) {
            return false;
        }

        return (bool) ($record === null
            ? $policy->{$method}($user)
            : $policy->{$method}($user, $record));
    }

    /**
     * @return class-string|null
     */
    protected static function resolveSystemAdminPolicy(): ?string
    {
        $reflection = new ReflectionClass(static::class);

        if (! $reflection->hasProperty('systemAdminPolicy')) {
            return null;
        }

        $value = $reflection->getStaticPropertyValue('systemAdminPolicy');

        return is_string($value) && class_exists($value) ? $value : null;
    }
}
