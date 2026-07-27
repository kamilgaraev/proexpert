<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Access;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAccessService;
use App\BusinessModules\Core\Reporting\Application\Access\ReportActorLoader;
use App\BusinessModules\Core\Reporting\Application\Access\ReportSourceAccessResolver;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;

final class ReportAccessServiceTest extends TestCase
{
    public function test_owner_permissions_pass_all_base_operations_without_role_slug_checks(): void
    {
        $permissions = [
            'definition.audit',
            'definition.export',
            'definition.sensitive',
            'definition.view',
            'reports.audit',
            'reports.download',
            'reports.export',
            'reports.manage',
            'reports.run',
            'reports.sensitive',
            'reports.view',
        ];
        $service = $this->service($permissions, true);
        $context = $this->context($permissions);
        $definition = $this->definition();

        foreach (ReportOperation::cases() as $operation) {
            $source = $operation === ReportOperation::DRILL_DOWN ? $this->source() : null;
            $visibility = $service->assertOperation($context, $definition, $operation, $source);
            self::assertTrue($visibility->canView);
        }
    }

    public function test_viewer_can_view_but_cannot_run(): void
    {
        $permissions = ['definition.view', 'reports.view'];
        $service = $this->service($permissions);
        $context = $this->context($permissions);

        self::assertTrue($service->assertOperation($context, $this->definition(), ReportOperation::VIEW, null)->canView);
        $this->expectScopeForbidden();
        $service->assertOperation($context, $this->definition(), ReportOperation::RUN, null);
    }

    public function test_runner_cannot_export(): void
    {
        $permissions = ['definition.view', 'reports.run', 'reports.view'];
        $this->expectScopeForbidden();

        $this->service($permissions)->assertOperation(
            $this->context($permissions),
            $this->definition(),
            ReportOperation::EXPORT,
            null,
        );
    }

    public function test_exporter_without_download_cannot_receive_link(): void
    {
        $permissions = ['definition.export', 'reports.export', 'reports.view'];
        $this->expectScopeForbidden();

        $this->service($permissions)->assertOperation(
            $this->context($permissions),
            $this->definition(),
            ReportOperation::DOWNLOAD,
            null,
        );
    }

    public function test_manage_permission_never_expands_run_or_export(): void
    {
        $permissions = ['definition.view', 'reports.manage', 'reports.view'];
        $service = $this->service($permissions);
        self::assertTrue($service->assertOperation(
            $this->context($permissions),
            $this->definition(),
            ReportOperation::MANAGE,
            null,
        )->canManage);
        $this->expectScopeForbidden();

        $service->assertOperation($this->context($permissions), $this->definition(), ReportOperation::RUN, null);
    }

    public function test_definition_view_permission_is_required_in_addition_to_global_view(): void
    {
        $permissions = ['reports.view'];
        $this->expectScopeForbidden();

        $this->service($permissions)->assertOperation(
            $this->context($permissions),
            $this->definition(),
            ReportOperation::VIEW,
            null,
        );
    }

    public function test_definition_export_permission_is_required_in_addition_to_global_export(): void
    {
        $permissions = ['reports.export', 'reports.view'];
        $this->expectScopeForbidden();

        $this->service($permissions)->assertOperation(
            $this->context($permissions),
            $this->definition(),
            ReportOperation::EXPORT,
            null,
        );
    }

    public function test_missing_sensitive_and_audit_permissions_return_redacted_visibility(): void
    {
        $permissions = ['definition.view', 'reports.view'];
        $visibility = $this->service($permissions)->assertOperation(
            $this->context($permissions),
            $this->definition(),
            ReportOperation::VIEW,
            null,
        );

        self::assertFalse($visibility->canViewSensitive);
        self::assertFalse($visibility->canViewAudit);
    }

    public function test_sensitive_and_audit_values_require_global_and_definition_permissions(): void
    {
        $permissions = [
            'definition.audit',
            'definition.sensitive',
            'definition.view',
            'reports.audit',
            'reports.sensitive',
            'reports.view',
        ];
        $visibility = $this->service($permissions)->assertOperation(
            $this->context($permissions),
            $this->definition(),
            ReportOperation::VIEW_SENSITIVE,
            null,
        );

        self::assertTrue($visibility->canViewSensitive);
        self::assertTrue($visibility->canViewAudit);
    }

    public function test_parent_scope_without_resource_allowlist_cannot_drill_down(): void
    {
        $permissions = ['definition.view', 'reports.view'];
        $this->expectScopeForbidden();

        $this->service($permissions, false)->assertOperation(
            $this->context($permissions),
            $this->definition(),
            ReportOperation::DRILL_DOWN,
            $this->source(),
        );
    }

    public function test_foreign_and_missing_sources_have_identical_public_denial(): void
    {
        $permissions = ['definition.view', 'reports.view'];
        $resolver = new class implements ReportSourceAccessResolver {
            public function canAccess(ReportExecutionContext $context, ReportSourceRef $source): bool
            {
                throw new \RuntimeException('foreign_source_identifier');
            }
        };
        $service = new ReportAccessService($this->loader($permissions), $resolver);
        $context = $this->context($permissions);
        $definition = $this->definition();

        $foreign = $this->captureDenial(fn () => $service->assertOperation($context, $definition, ReportOperation::DRILL_DOWN, $this->source()));
        $missing = $this->captureDenial(fn () => $service->assertOperation($context, $definition, ReportOperation::DRILL_DOWN, null));

        self::assertSame($foreign->errorCode, $missing->errorCode);
        self::assertSame($foreign->safeFields, $missing->safeFields);
        self::assertSame([], $foreign->safeFields);
    }

    public function test_revoked_actor_is_reloaded_and_denied_before_download(): void
    {
        $permissions = ['definition.export', 'reports.download', 'reports.export', 'reports.view'];
        $loader = new class implements ReportActorLoader {
            public function loadActive(int $actorId): ReportActor
            {
                throw new \RuntimeException('actor_revoked');
            }
        };
        $this->expectScopeForbidden();

        (new ReportAccessService($loader, $this->sourceResolver(true)))->assertOperation(
            $this->context($permissions),
            $this->definition(),
            ReportOperation::DOWNLOAD,
            null,
        );
    }

    private function service(array $permissions, bool $sourceAllowed = true): ReportAccessService
    {
        return new ReportAccessService($this->loader($permissions), $this->sourceResolver($sourceAllowed));
    }

    private function loader(array $permissions): ReportActorLoader
    {
        return new class($permissions) implements ReportActorLoader {
            public function __construct(private readonly array $permissions)
            {
            }

            public function loadActive(int $actorId): ReportActor
            {
                return new ReportActor($actorId, 'active', $this->permissions);
            }
        };
    }

    private function sourceResolver(bool $allowed): ReportSourceAccessResolver
    {
        return new class($allowed) implements ReportSourceAccessResolver {
            public function __construct(private readonly bool $allowed)
            {
            }

            public function canAccess(ReportExecutionContext $context, ReportSourceRef $source): bool
            {
                return $this->allowed;
            }
        };
    }

    private function context(array $permissions): ReportExecutionContext
    {
        $actor = new ReportActor(41, 'active', $permissions);

        return (new ReportExecutionContextBuilder())
            ->actor($actor)
            ->build();
    }

    private function definition(): ReportDefinition
    {
        return (new ReportDefinitionBuilder())
            ->permissionPolicy(new ReportPermissionPolicy(
                ['definition.view'],
                ['definition.export'],
                ['definition.sensitive'],
                ['definition.audit'],
            ))
            ->payload();
    }

    private function source(): ReportSourceRef
    {
        return new ReportSourceRef(
            'projects',
            'sealed',
            'snapshot',
            'v1',
            'watermark',
            1,
            new Sha256Hash(str_repeat('f', 64)),
        );
    }

    private function captureDenial(callable $operation): ReportContractException
    {
        try {
            $operation();
        } catch (ReportContractException $exception) {
            return $exception;
        }

        self::fail('Expected reporting access denial.');
    }

    private function expectScopeForbidden(): void
    {
        $this->expectException(ReportContractException::class);
        $this->expectExceptionMessage(ReportErrorCode::REPORT_SCOPE_FORBIDDEN->value);
    }
}
