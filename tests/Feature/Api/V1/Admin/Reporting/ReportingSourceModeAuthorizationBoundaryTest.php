<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin\Reporting;

use App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionVisibilityResolver;
use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\LaravelReportHttpAuthorizationTargetResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\HermeticReportingHttpHarness;

final class ReportingSourceModeAuthorizationBoundaryTest extends TestCase
{
    public function test_source_mode_boundary_uses_real_resolver_orchestrator_and_definition_authorizer(): void
    {
        $components = (new HermeticReportingHttpHarness(true))->sourceModeBoundaryComponents();

        self::assertSame([
            'target_resolver' => LaravelReportHttpAuthorizationTargetResolver::class,
            'orchestrator' => ReportHttpAuthorizationOrchestrator::class,
            'visibility_resolver' => ReportDefinitionVisibilityResolver::class,
        ], $components);
    }

    #[DataProvider('allowedCases')]
    public function test_source_mode_route_reaches_action_with_exact_permissions(
        string $caseId,
        int $status,
    ): void {
        $record = (new HermeticReportingHttpHarness(true))->runSourceModeAuthorizationCase($caseId);

        self::assertSame($caseId, $record['case_id']);
        self::assertSame($status, $record['status']);
        self::assertNull($record['code']);
        self::assertSame(1, $record['action_calls']);
        self::assertSame(1, $record['actor_loads']);
        self::assertGreaterThanOrEqual(2, $record['module_checks']);
    }

    #[DataProvider('deniedCases')]
    public function test_source_mode_revocation_fails_before_action(
        string $caseId,
        int $expectedModuleChecks,
        int $expectedActorLoads,
    ): void {
        $record = (new HermeticReportingHttpHarness(true))->runSourceModeAuthorizationCase($caseId);

        self::assertSame($caseId, $record['case_id']);
        self::assertSame(403, $record['status']);
        self::assertSame('REPORT_SCOPE_FORBIDDEN', $record['code']);
        self::assertSame(0, $record['action_calls']);
        self::assertSame($expectedActorLoads, $record['actor_loads']);
        self::assertSame($expectedModuleChecks, $record['module_checks']);
    }

    public static function allowedCases(): iterable
    {
        yield ['catalog', 200];
        yield ['run_create', 201];
        yield ['run_show', 200];
        yield ['run_rows', 200];
        yield ['run_drill_down', 200];
        yield ['run_retry', 202];
        yield ['run_cancel', 200];
        yield ['export_create_xlsx', 201];
        yield ['export_create_pdf', 201];
        yield ['export_show', 200];
        yield ['export_retry', 202];
        yield ['export_cancel', 200];
        yield ['export_download', 200];
    }

    public static function deniedCases(): iterable
    {
        yield ['module_revoked_before_http', 1, 0];
        yield ['module_revoked_at_final_decision', 2, 1];
        yield ['view_permission_revoked', 2, 1];
        yield ['xlsx_permission_revoked', 2, 1];
        yield ['pdf_permission_cannot_export_xlsx', 2, 1];
    }
}
