<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Access;

use App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionModuleAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportHttpAuthorizationTargetResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportModuleEntitlement;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Http\Admin\Middleware\AuthorizeReportDefinitionAccess;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class AuthorizeReportDefinitionAccessTest extends TestCase
{
    public function test_resource_route_uses_server_owned_definition_module(): void
    {
        $definition = $this->sourceDefinition('a');
        $resolver = new DefinitionAccessTargetResolver([$definition]);
        $modules = new DefinitionAccessModuleEntitlement(['act-reporting']);
        $middleware = new AuthorizeReportDefinitionAccess(
            $resolver,
            new ReportDefinitionModuleAuthorizer($modules),
        );
        $request = $this->request('admin.reports.runs.store', ['reportCode' => 'server_report']);
        $called = false;

        $response = $middleware->handle($request, static function () use (&$called): Response {
            $called = true;

            return new Response('', 204);
        });

        self::assertSame(204, $response->getStatusCode());
        self::assertTrue($called);
        self::assertSame(['act-reporting'], $modules->checkedModules);
        self::assertSame(['server_report'], $resolver->createRunCodes);
    }

    public function test_contract_settlement_options_route_uses_server_owned_definition_module(): void
    {
        $definition = $this->sourceDefinition('2');
        $resolver = new DefinitionAccessTargetResolver([$definition]);
        $modules = new DefinitionAccessModuleEntitlement(['act-reporting']);
        $middleware = new AuthorizeReportDefinitionAccess(
            $resolver,
            new ReportDefinitionModuleAuthorizer($modules),
        );
        $request = $this->request('admin.reports.contract-settlement-exposure.options', [
            'reportCode' => 'contract_settlement_exposure',
        ]);
        $called = false;

        $response = $middleware->handle($request, static function () use (&$called): Response {
            $called = true;

            return new Response('', 204);
        });

        self::assertSame(204, $response->getStatusCode());
        self::assertTrue($called);
        self::assertSame(['act-reporting'], $modules->checkedModules);
        self::assertSame(['contract_settlement_exposure'], $resolver->createRunCodes);
    }

    public function test_catalog_records_only_module_accessible_definition_hashes(): void
    {
        $generic = (new ReportDefinitionBuilder)
            ->definitionHash(new Sha256Hash(str_repeat('b', 64)))
            ->payload();
        $source = $this->sourceDefinition('c');
        $request = $this->request('admin.reports.catalog');
        $middleware = new AuthorizeReportDefinitionAccess(
            new DefinitionAccessTargetResolver([$generic, $source]),
            new ReportDefinitionModuleAuthorizer(new DefinitionAccessModuleEntitlement(['act-reporting'])),
        );

        $middleware->handle($request, static fn (): Response => new Response('', 204));

        self::assertSame(
            [$source->definitionHash->value],
            $request->attributes->get(AuthorizeReportDefinitionAccess::ACCESSIBLE_DEFINITION_HASHES_ATTRIBUTE),
        );
    }

    public function test_identity_route_resolves_persisted_run_definition_instead_of_client_values(): void
    {
        $resolver = new DefinitionAccessTargetResolver([$this->sourceDefinition('e')]);
        $modules = new DefinitionAccessModuleEntitlement(['act-reporting']);
        $middleware = new AuthorizeReportDefinitionAccess(
            $resolver,
            new ReportDefinitionModuleAuthorizer($modules),
        );
        $request = $this->request('admin.reports.runs.show', ['runId' => '01J00000000000000000000001']);
        $request->request->set('report_code', 'client_forgery');

        $middleware->handle($request, static fn (): Response => new Response('', 204));

        self::assertSame(
            [['01J00000000000000000000001', ReportOperation::VIEW]],
            $resolver->runCalls,
        );
        self::assertSame(['act-reporting'], $modules->checkedModules);
    }

    public function test_unknown_route_shape_fails_closed_before_next_handler(): void
    {
        $middleware = new AuthorizeReportDefinitionAccess(
            new DefinitionAccessTargetResolver([$this->sourceDefinition('d')]),
            new ReportDefinitionModuleAuthorizer(new DefinitionAccessModuleEntitlement(['act-reporting'])),
        );
        $called = false;

        try {
            $middleware->handle(
                $this->request('admin.reports.unknown'),
                static function () use (&$called): Response {
                    $called = true;

                    return new Response('', 204);
                },
            );
            self::fail('Unknown route must be denied.');
        } catch (ReportContractException $exception) {
            self::assertSame('REPORT_SCOPE_FORBIDDEN', $exception->getMessage());
            self::assertFalse($called);
        }
    }

    public function test_revoked_source_module_fails_closed_before_next_handler(): void
    {
        $middleware = new AuthorizeReportDefinitionAccess(
            new DefinitionAccessTargetResolver([$this->sourceDefinition('f')]),
            new ReportDefinitionModuleAuthorizer(new DefinitionAccessModuleEntitlement(['reports'])),
        );
        $called = false;

        try {
            $middleware->handle(
                $this->request('admin.reports.runs.store', ['reportCode' => 'server_report']),
                static function () use (&$called): Response {
                    $called = true;

                    return new Response('', 204);
                },
            );
            self::fail('Revoked source module must be denied.');
        } catch (ReportContractException $exception) {
            self::assertSame('REPORT_SCOPE_FORBIDDEN', $exception->getMessage());
            self::assertFalse($called);
        }
    }

    public function test_contract_settlement_options_route_denies_revoked_source_module(): void
    {
        $middleware = new AuthorizeReportDefinitionAccess(
            new DefinitionAccessTargetResolver([$this->sourceDefinition('3')]),
            new ReportDefinitionModuleAuthorizer(new DefinitionAccessModuleEntitlement(['reports'])),
        );
        $called = false;

        try {
            $middleware->handle(
                $this->request('admin.reports.contract-settlement-exposure.options', [
                    'reportCode' => 'contract_settlement_exposure',
                ]),
                static function () use (&$called): Response {
                    $called = true;

                    return new Response('', 204);
                },
            );
            self::fail('Revoked contract settlement source module must be denied.');
        } catch (ReportContractException $exception) {
            self::assertSame('REPORT_SCOPE_FORBIDDEN', $exception->getMessage());
            self::assertFalse($called);
        }
    }

    public function test_export_routes_use_exact_persisted_resolver_operations(): void
    {
        $resolver = new DefinitionAccessTargetResolver([$this->sourceDefinition('9')]);
        $middleware = new AuthorizeReportDefinitionAccess(
            $resolver,
            new ReportDefinitionModuleAuthorizer(new DefinitionAccessModuleEntitlement(['act-reporting'])),
        );
        $next = static fn (): Response => new Response('', 204);

        $middleware->handle($this->request('admin.reports.exports.store', [
            'runId' => '01J00000000000000000000001',
        ]), $next);
        foreach ([
            'admin.reports.exports.show' => ReportOperation::VIEW,
            'admin.reports.exports.retry' => ReportOperation::EXPORT,
            'admin.reports.exports.cancel' => ReportOperation::EXPORT,
            'admin.reports.exports.download-link' => ReportOperation::DOWNLOAD,
        ] as $routeName => $operation) {
            $middleware->handle($this->request($routeName, [
                'exportId' => '01J00000000000000000000002',
            ]), $next);
        }

        self::assertSame(
            [['01J00000000000000000000001', null]],
            $resolver->createExportCalls,
        );
        self::assertSame([
            ['01J00000000000000000000002', ReportOperation::VIEW],
            ['01J00000000000000000000002', ReportOperation::EXPORT],
            ['01J00000000000000000000002', ReportOperation::EXPORT],
            ['01J00000000000000000000002', ReportOperation::DOWNLOAD],
        ], $resolver->exportCalls);
    }

    private function sourceDefinition(string $hashCharacter): ReportDefinition
    {
        return (new ReportDefinitionBuilder)
            ->definitionHash(new Sha256Hash(str_repeat($hashCharacter, 64)))
            ->sourceModule('act-reporting')
            ->coreAccessMode(ReportCoreAccessMode::SOURCE_MODULE_REPORT)
            ->formats(['xlsx'])
            ->permissionPolicy(new ReportPermissionPolicy(
                ['act_reports.view'],
                ['act_reports.export.excel'],
                [],
                [],
            ))
            ->payload();
    }

    private function request(string $name, array $parameters = []): Request
    {
        $request = Request::create('/api/v1/admin/reports/test');
        $request->attributes->set('current_organization_id', 7);
        $request->setUserResolver(static fn (): User => new User(['id' => 41]));
        $route = (new Route(['GET'], '/api/v1/admin/reports/test', static fn () => null))
            ->name($name);
        $route->bind($request);
        foreach ($parameters as $key => $value) {
            $route->setParameter($key, $value);
        }
        $request->setRouteResolver(static fn (): Route => $route);

        return $request;
    }
}

final class DefinitionAccessTargetResolver implements ReportHttpAuthorizationTargetResolver
{
    public array $createRunCodes = [];

    public array $runCalls = [];

    public array $createExportCalls = [];

    public array $exportCalls = [];

    public function __construct(private readonly array $definitions) {}

    public function createRun(string $reportCode): CurrentReportAuthorizationTarget
    {
        $this->createRunCodes[] = $reportCode;

        return new CurrentReportAuthorizationTarget($this->definitions[0], ReportOperation::RUN, null);
    }

    public function run(string $runId, ReportOperation $operation): CurrentReportAuthorizationTarget
    {
        $this->runCalls[] = [$runId, $operation];

        return $this->target($operation, null);
    }

    public function createExport(string $runId, ?string $format): CurrentReportAuthorizationTarget
    {
        $this->createExportCalls[] = [$runId, $format];

        return $this->target(ReportOperation::EXPORT, $format);
    }

    public function export(string $exportId, ReportOperation $operation): CurrentReportAuthorizationTarget
    {
        $this->exportCalls[] = [$exportId, $operation];

        return $this->target($operation, 'xlsx');
    }

    public function catalog(): array
    {
        return array_map(
            static fn (ReportDefinition $definition): CurrentReportAuthorizationTarget => new CurrentReportAuthorizationTarget(
                $definition,
                ReportOperation::VIEW,
                null,
            ),
            $this->definitions,
        );
    }

    private function target(ReportOperation $operation, ?string $format): CurrentReportAuthorizationTarget
    {
        $definition = $this->definitions[0];
        $snapshot = in_array($operation, [
            ReportOperation::EXPORT,
            ReportOperation::DOWNLOAD,
            ReportOperation::DRILL_DOWN,
        ], true) || $format !== null
            ? new ReportSnapshotRef(
                'report',
                'snapshot',
                new ReportScope(7, [7], [], [], new DateTimeZone('UTC')),
                $definition->definitionHash,
                $definition->formulaVersion,
                new Sha256Hash(str_repeat('8', 64)),
                new DateTimeImmutable('2026-08-01T00:00:00Z'),
                null,
                [],
                ReportSnapshotClassification::OPERATIONAL,
                null,
            )
            : null;

        return new CurrentReportAuthorizationTarget($definition, $operation, $snapshot, $format);
    }
}

final class DefinitionAccessModuleEntitlement implements ReportModuleEntitlement
{
    public array $checkedModules = [];

    public function __construct(private readonly array $allowedModules) {}

    public function organizationHasModule(int $organizationId, string $moduleSlug): bool
    {
        $this->checkedModules[] = $moduleSlug;

        return $organizationId === 7 && in_array($moduleSlug, $this->allowedModules, true);
    }
}
