<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Access;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAccessService;
use App\BusinessModules\Core\Reporting\Application\Access\ReportActorLoader;
use App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionModuleAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionVisibilityResolver;
use App\BusinessModules\Core\Reporting\Application\Access\ReportSourceAccessResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportModuleEntitlement;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
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

    public function test_sensitive_operation_requires_sensitive_visibility_independently(): void
    {
        $permissions = ['definition.view', 'reports.view'];
        $this->expectScopeForbidden();

        $this->service($permissions)->assertOperation(
            $this->context($permissions),
            $this->definition(),
            ReportOperation::VIEW_SENSITIVE,
            null,
        );
    }

    public function test_audit_operation_requires_audit_visibility_independently(): void
    {
        $permissions = [
            'definition.sensitive',
            'definition.view',
            'reports.sensitive',
            'reports.view',
        ];
        $this->expectScopeForbidden();

        $this->service($permissions)->assertOperation(
            $this->context($permissions),
            $this->definition(),
            ReportOperation::VIEW_AUDIT,
            null,
        );
    }

    public function test_audit_operation_succeeds_with_exact_audit_visibility_without_sensitive_visibility(): void
    {
        $permissions = [
            'definition.audit',
            'definition.view',
            'reports.audit',
            'reports.view',
        ];

        $visibility = $this->service($permissions)->assertOperation(
            $this->context($permissions),
            $this->definition(),
            ReportOperation::VIEW_AUDIT,
            null,
        );

        self::assertTrue($visibility->canViewAudit);
        self::assertFalse($visibility->canViewSensitive);
    }

    public function test_global_and_definition_halves_cannot_authorize_classified_views_independently(): void
    {
        foreach ([
            [ReportOperation::VIEW_SENSITIVE, ['definition.view', 'reports.sensitive', 'reports.view']],
            [ReportOperation::VIEW_SENSITIVE, ['definition.sensitive', 'definition.view', 'reports.view']],
            [ReportOperation::VIEW_AUDIT, ['definition.view', 'reports.audit', 'reports.view']],
            [ReportOperation::VIEW_AUDIT, ['definition.audit', 'definition.view', 'reports.view']],
        ] as [$operation, $permissions]) {
            $error = $this->captureDenial(fn () => $this->service($permissions)->assertOperation(
                $this->context($permissions),
                $this->definition(),
                $operation,
                null,
            ));
            self::assertSame(ReportErrorCode::REPORT_SCOPE_FORBIDDEN, $error->errorCode);
        }
    }

    public function test_audit_visibility_does_not_grant_sensitive_visibility(): void
    {
        $permissions = [
            'definition.audit',
            'definition.view',
            'reports.audit',
            'reports.view',
        ];
        $this->expectScopeForbidden();

        $this->service($permissions)->assertOperation(
            $this->context($permissions),
            $this->definition(),
            ReportOperation::VIEW_SENSITIVE,
            null,
        );
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
        $resolver = new class implements ReportSourceAccessResolver
        {
            public function assertAccessible(
                ReportExecutionContext $context,
                ReportDefinition $definition,
                ReportSourceRef $source,
            ): void {
                throw new \RuntimeException('foreign_source_identifier');
            }
        };
        $service = new ReportAccessService(
            $this->loader($permissions),
            $resolver,
            $this->moduleAuthorizer(),
        );
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
        $loader = new class implements ReportActorLoader
        {
            public function loadActive(int $actorId): ReportActor
            {
                throw new \RuntimeException('actor_revoked');
            }
        };
        $this->expectScopeForbidden();

        (new ReportAccessService(
            $loader,
            $this->sourceResolver(true),
            $this->moduleAuthorizer(),
        ))->assertOperation(
            $this->context($permissions),
            $this->definition(),
            ReportOperation::DOWNLOAD,
            null,
        );
    }

    public function test_source_access_is_bound_to_exact_context_definition_and_source(): void
    {
        $permissions = ['definition.view', 'reports.view'];
        $context = $this->context($permissions);
        $definition = $this->definition();
        $source = $this->source();
        $resolver = new class implements ReportSourceAccessResolver
        {
            public ?ReportExecutionContext $context = null;

            public ?ReportDefinition $definition = null;

            public ?ReportSourceRef $source = null;

            public function assertAccessible(
                ReportExecutionContext $context,
                ReportDefinition $definition,
                ReportSourceRef $source,
            ): void {
                $this->context = $context;
                $this->definition = $definition;
                $this->source = $source;
            }
        };
        $service = new ReportAccessService(
            $this->loader($permissions),
            $resolver,
            $this->moduleAuthorizer(),
        );

        $service->assertOperation($context, $definition, ReportOperation::DRILL_DOWN, $source);

        self::assertSame($context, $resolver->context);
        self::assertSame($definition, $resolver->definition);
        self::assertSame($source, $resolver->source);
    }

    public function test_actor_loader_contract_error_is_scrubbed_at_operation_boundary(): void
    {
        $permissions = ['definition.export', 'reports.download', 'reports.export', 'reports.view'];
        $loader = new class implements ReportActorLoader
        {
            public function loadActive(int $actorId): ReportActor
            {
                throw ReportContractException::fromCode(
                    ReportErrorCode::REPORT_NOT_FOUND,
                    ['fields' => 'snapshot_id'],
                );
            }
        };
        $service = new ReportAccessService(
            $loader,
            $this->sourceResolver(true),
            $this->moduleAuthorizer(),
        );

        $error = $this->captureDenial(fn () => $service->assertOperation(
            $this->context($permissions),
            $this->definition(),
            ReportOperation::DOWNLOAD,
            null,
        ));

        self::assertSame(ReportErrorCode::REPORT_SCOPE_FORBIDDEN, $error->errorCode);
        self::assertSame([], $error->safeFields);
        self::assertInstanceOf(ReportContractException::class, $error->getPrevious());
    }

    public function test_source_module_report_uses_only_exact_definition_permissions(): void
    {
        $viewPermissions = ['act_reports.view'];
        $definition = $this->sourceModuleDefinition();
        $service = $this->service($viewPermissions);
        $context = $this->context($viewPermissions);

        self::assertTrue($service->assertOperation($context, $definition, ReportOperation::VIEW, null)->canView);
        self::assertTrue($service->assertOperation($context, $definition, ReportOperation::RUN, null)->canRun);
        self::assertTrue($service->assertOperation(
            $context,
            $definition,
            ReportOperation::DRILL_DOWN,
            $this->source(),
        )->canView);

        $this->expectScopeForbidden();
        $service->assertOperation($context, $definition, ReportOperation::EXPORT, null);
    }

    public function test_source_module_report_export_needs_view_and_exact_export_permissions(): void
    {
        $permissions = ['act_reports.view', 'act_reports.export.excel'];
        $definition = $this->sourceModuleDefinition();
        $visibility = $this->service($permissions)->assertOperation(
            $this->context($permissions),
            $definition,
            ReportOperation::DOWNLOAD,
            null,
            'xlsx',
        );

        self::assertTrue($visibility->canExport);
        self::assertTrue($visibility->canDownload);
        self::assertFalse($visibility->canManage);
        self::assertFalse($visibility->canViewSensitive);
        self::assertFalse($visibility->canViewAudit);
    }

    public function test_source_module_report_authorizes_only_the_requested_export_format(): void
    {
        $permissions = ['act_reports.view', 'act_reports.export.excel'];
        $definition = $this->sourceModuleDefinition(['xlsx', 'pdf']);
        $service = $this->service($permissions);
        $context = $this->context($permissions);

        self::assertTrue($service->assertOperation(
            $context,
            $definition,
            ReportOperation::EXPORT,
            null,
            'xlsx',
        )->canExport);

        $this->expectScopeForbidden();
        $service->assertOperation($context, $definition, ReportOperation::EXPORT, null, 'pdf');
    }

    public function test_generic_permissions_never_authorize_source_module_report(): void
    {
        $permissions = ['reports.view', 'reports.run', 'reports.export', 'reports.download'];
        $this->expectScopeForbidden();

        $this->service($permissions)->assertOperation(
            $this->context($permissions),
            $this->sourceModuleDefinition(),
            ReportOperation::VIEW,
            null,
        );
    }

    public function test_revoked_definition_module_denies_direct_application_service_call(): void
    {
        $permissions = ['act_reports.view'];
        $this->expectScopeForbidden();

        $this->service($permissions, true, false)->assertOperation(
            $this->context($permissions),
            $this->sourceModuleDefinition(),
            ReportOperation::VIEW,
            null,
        );
    }

    private function service(
        array $permissions,
        bool $sourceAllowed = true,
        bool $moduleAllowed = true,
    ): ReportAccessService {
        return new ReportAccessService(
            $this->loader($permissions),
            $this->sourceResolver($sourceAllowed),
            $this->moduleAuthorizer($moduleAllowed),
        );
    }

    private function moduleAuthorizer(bool $allowed = true): ReportDefinitionVisibilityResolver
    {
        return new ReportDefinitionVisibilityResolver(
            new ReportDefinitionModuleAuthorizer(new class($allowed) implements ReportModuleEntitlement
            {
                public function __construct(private readonly bool $allowed) {}

                public function organizationHasModule(int $organizationId, string $moduleSlug): bool
                {
                    return $organizationId === 1
                        && in_array($moduleSlug, ['reports', 'act-reporting'], true)
                        && $this->allowed;
                }
            }),
        );
    }

    private function loader(array $permissions): ReportActorLoader
    {
        return new class($permissions) implements ReportActorLoader
        {
            public function __construct(private readonly array $permissions) {}

            public function loadActive(int $actorId): ReportActor
            {
                return new ReportActor($actorId, 'active', $this->permissions);
            }
        };
    }

    private function sourceResolver(bool $allowed): ReportSourceAccessResolver
    {
        return new class($allowed) implements ReportSourceAccessResolver
        {
            public function __construct(private readonly bool $allowed) {}

            public function assertAccessible(
                ReportExecutionContext $context,
                ReportDefinition $definition,
                ReportSourceRef $source,
            ): void {
                if (! $this->allowed) {
                    throw new \RuntimeException('source_forbidden');
                }
            }
        };
    }

    private function context(array $permissions): ReportExecutionContext
    {
        $actor = new ReportActor(41, 'active', $permissions);

        return (new ReportExecutionContextBuilder)
            ->actor($actor)
            ->build();
    }

    private function definition(): ReportDefinition
    {
        return (new ReportDefinitionBuilder)
            ->permissionPolicy(new ReportPermissionPolicy(
                ['definition.view'],
                ['definition.export'],
                ['definition.sensitive'],
                ['definition.audit'],
            ))
            ->payload();
    }

    private function sourceModuleDefinition(array $formats = ['xlsx']): ReportDefinition
    {
        $exportPermissions = array_map(
            static fn (string $format): string => match ($format) {
                'xlsx' => 'act_reports.export.excel',
                'pdf' => 'act_reports.export.pdf',
            },
            $formats,
        );
        sort($exportPermissions, SORT_STRING);

        return (new ReportDefinitionBuilder)
            ->sourceModule('act-reporting')
            ->coreAccessMode(ReportCoreAccessMode::SOURCE_MODULE_REPORT)
            ->formats($formats)
            ->permissionPolicy(new ReportPermissionPolicy(
                ['act_reports.view'],
                $exportPermissions,
                [],
                [],
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
