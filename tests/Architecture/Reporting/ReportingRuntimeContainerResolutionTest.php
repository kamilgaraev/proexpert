<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Application\Access\ReportSourceAccessResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunCoordinator;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSavedViewReferenceResolver;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\ReportingCatalogServiceProvider;
use App\BusinessModules\Core\Reporting\ReportingContractsServiceProvider;
use App\BusinessModules\Core\Reporting\ReportingExecutionServiceProvider;
use App\Domain\Authorization\Services\AuthorizationService;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;

final class ReportingRuntimeContainerResolutionTest extends TestCase
{
    public function test_registered_providers_resolve_run_coordinator_and_create_action_with_persistence_ports_replaced(): void
    {
        $app = new Application(dirname(__DIR__, 3));
        (new ReportingContractsServiceProvider($app))->register();
        (new ReportingExecutionServiceProvider($app))->register();
        (new ReportingCatalogServiceProvider($app))->register();

        $app->instance(AuthorizationService::class, $this->createStub(AuthorizationService::class));
        $app->instance(ReportDefinitionRegistry::class, $this->createStub(ReportDefinitionRegistry::class));
        $app->instance(ReportSavedViewReferenceResolver::class, $this->createStub(ReportSavedViewReferenceResolver::class));
        $app->instance(ReportRunStore::class, $this->createStub(ReportRunStore::class));
        $app->instance(ReportExecutionClock::class, $this->createStub(ReportExecutionClock::class));

        self::assertInstanceOf(ReportSourceAccessResolver::class, $app->make(ReportSourceAccessResolver::class));
        self::assertInstanceOf(ReportRunCoordinator::class, $app->make(ReportRunCoordinator::class));
        self::assertInstanceOf(CreateReportRunAction::class, $app->make(CreateReportRunAction::class));
    }

    public function test_registered_source_resolver_accepts_only_definition_bound_schema(): void
    {
        $app = new Application(dirname(__DIR__, 3));
        (new ReportingExecutionServiceProvider($app))->register();
        $resolver = $app->make(ReportSourceAccessResolver::class);
        $context = (new ReportExecutionContextBuilder)->build();
        $definition = (new ReportDefinitionBuilder)->sourceSchemaVersion('v1_0_0')->payload();

        $resolver->assertAccessible(
            $context,
            $definition,
            $this->source($definition->sourceSchemaVersion),
        );

        try {
            $resolver->assertAccessible($context, $definition, $this->source('v2_0_0'));
            self::fail('A source from another schema was accepted.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_SCOPE_FORBIDDEN, $exception->errorCode);
        }
    }

    private function source(string $schemaVersion): ReportSourceRef
    {
        return new ReportSourceRef(
            'report_source',
            'immutable_snapshot',
            'snapshot_1',
            $schemaVersion,
            'watermark_1',
            0,
            new Sha256Hash(str_repeat('a', 64)),
        );
    }
}
