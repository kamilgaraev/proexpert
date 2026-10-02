<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Domain\Authorization\Services\PermissionResolver;
use App\Domain\Authorization\Services\ModulePermissionChecker;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\ValueObjects\ModulePermissionAliases;
use App\Services\Logging\LoggingService;
use App\Modules\Core\AccessController;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PermissionResolverAddonModuleAliasTest extends TestCase
{
    public function test_report_and_coefficient_prefixes_use_their_canonical_active_modules(): void
    {
        self::assertContains('file-management', ModulePermissionAliases::variants('report_files'));
        self::assertContains('rate-management', ModulePermissionAliases::variants('rate_coefficients'));
    }

    public function test_owner_module_grants_authorize_registered_read_permissions(): void
    {
        self::assertTrue($this->permits(['file-management' => ['*']], 'report_files', 'view'));
        self::assertTrue($this->permits(['rate-management' => ['*']], 'rate_coefficients', 'view'));
    }

    public function test_full_permission_resolution_uses_active_canonical_modules(): void
    {
        self::assertTrue($this->resolves('report_files.view', ['file-management'], 38));
        self::assertTrue($this->resolves('rate_coefficients.view', ['rate-management'], 38));
    }

    public function test_module_activation_and_organization_context_remain_required(): void
    {
        self::assertFalse($this->resolves('report_files.view', [], 38));
        self::assertFalse($this->resolves('rate_coefficients.view', [], 38));
        self::assertFalse($this->resolves('report_files.view', ['file-management'], 41));
        self::assertFalse($this->resolves('rate_coefficients.view', ['rate-management'], 41));
    }

    public function test_explicit_read_grants_preserve_action_boundaries(): void
    {
        self::assertTrue($this->permits(['file-management' => ['report_files.view']], 'report_files', 'view'));
        self::assertFalse($this->permits(['file-management' => ['report_files.view']], 'report_files', 'delete'));
        self::assertTrue($this->permits(['rate-management' => ['rate_coefficients.view']], 'rate_coefficients', 'view'));
        self::assertFalse($this->permits(['rate-management' => ['rate_coefficients.view']], 'rate_coefficients', 'edit'));
    }

    public function test_other_modules_and_personal_file_grants_do_not_authorize_report_reads(): void
    {
        self::assertFalse($this->permits(['file-management' => ['personal_files.view']], 'report_files', 'view'));
        self::assertFalse($this->permits(['rate-management' => ['*']], 'report_files', 'view'));
        self::assertFalse($this->permits(['file-management' => ['*']], 'rate_coefficients', 'view'));
        self::assertFalse($this->permits([], 'report_files', 'view'));
        self::assertFalse($this->permits([], 'rate_coefficients', 'view'));
    }

    private function resolves(string $permission, array $activeModules, int $organizationId): bool
    {
        $access = $this->createMock(AccessController::class);
        $access->method('hasModuleAccess')->willReturnCallback(
            static fn (int $scopeId, string $module): bool => $scopeId === 38 && in_array($module, $activeModules, true),
        );
        $checker = new ModulePermissionChecker($access);
        $resolver = new class($this->createMock(LoggingService::class), $checker) extends PermissionResolver
        {
            public function __construct(LoggingService $logging, ModulePermissionChecker $checker)
            {
                $this->logging = $logging;
                $this->moduleChecker = $checker;
            }

            public function getModulePermissions(UserRoleAssignment $assignment): array
            {
                return ['file-management' => ['*'], 'rate-management' => ['*']];
            }
        };

        $previousLogger = Log::getFacadeRoot();
        $previousCache = Cache::getFacadeRoot();
        Log::swap(new NullLogger);
        Cache::swap(new Repository(new ArrayStore));
        try {
            return $resolver->hasModulePermission(
                $this->createStub(UserRoleAssignment::class),
                $permission,
                ['organization_id' => $organizationId],
            );
        } finally {
            if ($previousLogger === null) {
                Log::clearResolvedInstance('log');
            } else {
                Log::swap($previousLogger);
            }
            if ($previousCache === null) {
                Cache::clearResolvedInstance('cache');
            } else {
                Cache::swap($previousCache);
            }
        }
    }

    private function permits(array $permissions, string $requestedModule, string $action): bool
    {
        $resolver = new class($this->createMock(LoggingService::class)) extends PermissionResolver
        {
            public function __construct(LoggingService $logging)
            {
                $this->logging = $logging;
            }

            public function permits(array $permissions, string $requestedModule, string $action): bool
            {
                $activeModule = match ($requestedModule) {
                    'report_files' => 'file-management',
                    'rate_coefficients' => 'rate-management',
                    default => $requestedModule,
                };

                return $this->checkModulePermission(
                    $permissions,
                    $activeModule,
                    $requestedModule,
                    $action,
                    $requestedModule.'.'.$action,
                );
            }
        };

        $previousLogger = Log::getFacadeRoot();
        Log::swap(new NullLogger);
        try {
            return $resolver->permits($permissions, $requestedModule, $action);
        } finally {
            if ($previousLogger === null) {
                Log::clearResolvedInstance('log');
            } else {
                Log::swap($previousLogger);
            }
        }
    }
}
