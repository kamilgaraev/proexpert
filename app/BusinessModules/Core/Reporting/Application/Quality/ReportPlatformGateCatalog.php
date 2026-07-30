<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Quality;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode;
use JsonException;

final class ReportPlatformGateCatalog
{
    private const IDS = ['QG-01', 'QG-02', 'QG-03', 'QG-04', 'QG-05', 'QG-06', 'QG-07', 'QG-08', 'QG-09', 'QG-10', 'QG-11', 'QG-12', 'QG-13', 'QG-14'];

    private const PLATFORM_PASSED = ['QG-01', 'QG-04', 'QG-05', 'QG-06', 'QG-09'];

    private const PASSED_SOURCE_PATHS = [
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

    private const OWNERS = ['backend', 'admin', 'both'];

    public function __construct(private ?string $path = null, private ?string $bytes = null)
    {
    }

    public static function fromBytes(string $bytes): self
    {
        return new self(bytes: $bytes);
    }

    public function records(): array
    {
        $bytes = $this->bytes ?? ($this->path === null ? false : @file_get_contents($this->path));
        if (! is_string($bytes)) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::MISSING);
        }
        try {
            $document = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
        }
        if (! is_array($document)
            || array_keys($document) !== ['artifact_id', 'schema_version', 'gates']
            || $document['artifact_id'] !== 'report_platform_gates'
            || $document['schema_version'] !== '1.0.0') {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
        }
        $gates = $document['gates'] ?? null;
        if (! is_array($gates) || ! array_is_list($gates) || count($gates) !== 14) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::CATALOG_COUNT_MISMATCH);
        }
        foreach ($gates as $index => $gate) {
            $id = self::IDS[$index];
            if (! is_array($gate) || array_keys($gate) !== ['id', 'platform_status', 'release_owner', 'command', 'minimum_count', 'schema_sha256', 'source_paths']
                || ($gate['id'] ?? null) !== $id
                || ! is_string($gate['platform_status'] ?? null)
                || ($gate['platform_status'] !== (in_array($id, self::PLATFORM_PASSED, true) ? 'passed' : 'pending'))
                || ! in_array($gate['release_owner'] ?? null, self::OWNERS, true)
                || ! $this->ownerMatchesGate($id, $gate['release_owner'])
                || ! is_string($gate['command'] ?? null) || preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $gate['command']) !== 1
                || ! is_int($gate['minimum_count'] ?? null) || $gate['minimum_count'] < 0
                || ! is_string($gate['schema_sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $gate['schema_sha256']) !== 1
                || ! is_array($gate['source_paths'] ?? null) || ! array_is_list($gate['source_paths'])
                || count($gate['source_paths']) !== count(array_unique($gate['source_paths']))
                || $gate['source_paths'] !== (self::PASSED_SOURCE_PATHS[$id] ?? [])) {
                throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
            }
            foreach ($gate['source_paths'] as $path) {
                if (! is_string($path) || preg_match('/^(?!\/)(?!.*\\.\\.)[A-Za-z0-9_.\/-]+$/', $path) !== 1) {
                    throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
                }
            }
        }

        return $gates;
    }

    public function hash(): string
    {
        $this->records();
        $bytes = $this->bytes ?? ($this->path === null ? false : file_get_contents($this->path));
        if (! is_string($bytes)) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::MISSING);
        }

        return hash('sha256', $bytes);
    }

    private function ownerMatchesGate(string $id, string $owner): bool
    {
        if ($id === 'QG-14') {
            return $owner === 'both';
        }

        if (in_array($id, ['QG-10', 'QG-11', 'QG-12', 'QG-13'], true)) {
            return $owner === 'admin';
        }

        return $owner === 'backend';
    }
}
