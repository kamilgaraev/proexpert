<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Application\Quality\ReportPlatformGateCatalog;
use App\BusinessModules\Core\Reporting\Application\Quality\ReportQualityGateException;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode;
use PHPUnit\Framework\TestCase;

final class ReportPlatformGateCatalogTest extends TestCase
{
    public function test_catalog_has_the_closed_ordered_phase_and_owner_map(): void
    {
        $gates = $this->catalog()->records();

        self::assertSame(array_map(static fn (int $number): string => sprintf('QG-%02d', $number), range(1, 14)), array_column($gates, 'id'));
        self::assertSame(['backend' => 9, 'admin' => 4, 'both' => 1], array_count_values(array_column($gates, 'release_owner')));
        self::assertSame('backend', $gates[5]['release_owner']);
        self::assertSame('both', $gates[13]['release_owner']);
        self::assertSame(['QG-01', 'QG-04', 'QG-05', 'QG-06', 'QG-09'], array_column(array_filter($gates, static fn (array $gate): bool => $gate['platform_status'] === 'passed'), 'id'));
    }

    public function test_catalog_rejects_unknown_member_and_reordered_gate(): void
    {
        $document = json_decode((string) file_get_contents($this->path()), true, 512, JSON_THROW_ON_ERROR);
        $document['gates'][0]['unexpected'] = true;
        $this->assertInvalid($document);

        unset($document['gates'][0]['unexpected']);
        [$document['gates'][0], $document['gates'][1]] = [$document['gates'][1], $document['gates'][0]];
        $this->assertInvalid($document, ReportQualityGateFailureCode::INVALID);
    }

    public function test_pending_gates_cannot_claim_platform_source_artifacts(): void
    {
        $document = json_decode((string) file_get_contents($this->path()), true, 512, JSON_THROW_ON_ERROR);
        $document['gates'][1]['source_paths'] = ['docs/reports/contracts/report-conformance-evidence.schema.json'];

        $this->assertInvalid($document);
    }

    public function test_passed_gates_require_their_exact_non_empty_source_path_catalog(): void
    {
        $document = json_decode((string) file_get_contents($this->path()), true, 512, JSON_THROW_ON_ERROR);
        foreach ($this->passedSourcePaths() as $gateId => $sourcePaths) {
            $gate = array_search($gateId, array_column($document['gates'], 'id'), true);
            self::assertIsInt($gate);
            self::assertSame($sourcePaths, $document['gates'][$gate]['source_paths']);
        }
    }

    public function test_passed_gates_reject_incomplete_or_alternative_source_path_sets(): void
    {
        foreach ($this->passedSourcePaths() as $gateId => $sourcePaths) {
            $document = json_decode((string) file_get_contents($this->path()), true, 512, JSON_THROW_ON_ERROR);
            $gate = array_search($gateId, array_column($document['gates'], 'id'), true);
            self::assertIsInt($gate);

            array_pop($document['gates'][$gate]['source_paths']);
            $this->assertInvalid($document);

            $document = json_decode((string) file_get_contents($this->path()), true, 512, JSON_THROW_ON_ERROR);
            $document['gates'][$gate]['source_paths'][array_key_last($sourcePaths)] = 'tests/Fixtures/Reporting/Quality/invented-source.json';
            $this->assertInvalid($document);
        }
    }

    private function passedSourcePaths(): array
    {
        return [
            'QG-01' => [
                'app/BusinessModules/Core/Reporting/resources/management-catalog.v1.yaml',
                'app/BusinessModules/Core/Reporting/resources/management-catalog.v1.schema.json',
                'app/BusinessModules/Core/Reporting/resources/official-document-catalog.v1.yaml',
                'app/BusinessModules/Core/Reporting/resources/official-document-catalog.v1.schema.json',
                'tests/Architecture/Reporting/ReportManifestIdentityContractTest.php',
                'tests/Architecture/Reporting/ReportingCatalogBindingsTest.php',
            ],
            'QG-04' => [
                'tests/Fixtures/Reporting/Manifest/management.valid.yaml',
                'tests/Fixtures/Reporting/Manifest/official.valid.yaml',
                'tests/Fixtures/Reporting/Conformance/report-conformance-evidence.valid.json',
                'docs/reports/contracts/report-conformance-evidence.schema.json',
                'tests/Architecture/Reporting/ReportConformanceEvidenceSchemaTest.php',
                'tests/Unit/Reporting/Conformance/ReportSourceConformanceHarnessTest.php',
            ],
            'QG-05' => [
                'app/BusinessModules/Core/Reporting/routes.php',
                'app/BusinessModules/Core/Reporting/Application/SavedViews/ReportSavedViewService.php',
                'docs/reports/contracts/reporting-admin-resources.v1.schema.json',
                'docs/reports/contracts/report-subscription-resources.v1.schema.json',
                'tests/Architecture/Reporting/ReportWorkspaceRouteContractTest.php',
                'tests/Architecture/Reporting/ReportSubscriptionRouteContractTest.php',
                'tests/Architecture/Reporting/ReportSubscriptionPageIsolationTest.php',
                'tests/Unit/Reporting/Workspace/ReportWorkspacePreferencesServiceTest.php',
                'tests/Unit/Reporting/Cursors/SignedReportSavedViewCursorCodecTest.php',
                'tests/Unit/Reporting/Subscriptions/ReportSubscriptionCoordinatorTest.php',
            ],
            'QG-06' => [
                'app/BusinessModules/Core/Reporting/routes.php',
                'app/BusinessModules/Core/Reporting/Http/Admin/Middleware/RenderReportErrors.php',
                'app/BusinessModules/Core/Reporting/Application/Errors/ReportErrorCatalog.php',
                'docs/reports/contracts/plan-1a-gate-evidence.schema.json',
                'docs/reports/contracts/reporting-admin-resources.v1.schema.json',
                'tests/Architecture/Reporting/ReportingRouteSnapshotTest.php',
                'tests/Architecture/Reporting/PlanOneAHandoffContractTest.php',
                'tests/Unit/Reporting/Http/ReportResourceSchemaTest.php',
                'tests/Unit/Reporting/Errors/ReportErrorCatalogTest.php',
                'tests/Feature/Api/V1/Admin/Reporting/ReportingMalformedRequestContractTest.php',
                'tests/Fixtures/Reporting/Evidence/plan-1a-ci-malformed.valid.json',
            ],
            'QG-09' => [
                'scripts/reporting/run-plan-1a-gates.php',
                'scripts/reporting/run-plan-1b-gate.php',
                'tests/Fixtures/Reporting/Evidence/plan-1a-command-ledger.valid.json',
                'tests/Fixtures/Reporting/Evidence/plan-1a-completion.valid.json',
                'tests/Fixtures/Reporting/plan-1b-completion.valid.json',
                'docs/reports/contracts/plan-1b-evidence.schema.json',
                'tests/Unit/Reporting/Tooling/RunPlanOneAGatesTest.php',
                'tests/Unit/Reporting/Evidence/PlanOneBGateArtifactRecorderTest.php',
                'tests/Architecture/Reporting/PlanOneBCrossFileSymbolTest.php',
            ],
        ];
    }

    private function catalog(): ReportPlatformGateCatalog
    {
        return new ReportPlatformGateCatalog($this->path());
    }

    private function path(): string
    {
        return dirname(__DIR__, 3).'/docs/reports/contracts/report-platform-gates.v1.json';
    }

    private function assertInvalid(array $document, ReportQualityGateFailureCode $failureCode = ReportQualityGateFailureCode::INVALID): void
    {
        $path = tempnam(sys_get_temp_dir(), 'report-platform-gates-');
        self::assertNotFalse($path);
        file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));
        try {
            $catalog = new ReportPlatformGateCatalog($path);
            try {
                $catalog->records();
                self::fail('Ожидалась ошибка закрытого каталога.');
            } catch (ReportQualityGateException $exception) {
                self::assertSame($failureCode, $exception->failureCode);
            }
        } finally {
            unlink($path);
        }
    }
}
