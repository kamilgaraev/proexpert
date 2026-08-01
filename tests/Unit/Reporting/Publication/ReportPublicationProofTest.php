<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationProof;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportPublicationProofTest extends TestCase
{
    public function test_valid_proof_is_canonical_and_content_addressed(): void
    {
        $payload = self::payload();

        $proof = ReportPublicationProof::fromArray($payload);

        self::assertSame($payload, $proof->payload());
        self::assertSame(hash('sha256', $proof->canonicalBytes()), $proof->digest()->value);
        self::assertSame(['xlsx'], $proof->exportFormats());
    }

    #[DataProvider('invalidProofProvider')]
    public function test_malformed_or_unsealed_proof_is_rejected(callable $mutate): void
    {
        $payload = self::payload();
        $mutate($payload);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_proof_invalid');

        ReportPublicationProof::fromArray($payload);
    }

    public static function invalidProofProvider(): iterable
    {
        yield 'extra root field' => [static function (array &$payload): void {
            $payload['approved'] = true;
        }];
        yield 'uppercase hash' => [static function (array &$payload): void {
            $payload['binding_sha256'] = str_repeat('A', 64);
        }];
        yield 'timestamp without microseconds' => [static function (array &$payload): void {
            $payload['ci']['completed_at_utc'] = '2026-08-01T01:02:03Z';
        }];
        yield 'impossible UTC date' => [static function (array &$payload): void {
            $payload['ci']['completed_at_utc'] = '2026-02-31T01:02:03.123456Z';
        }];
        yield 'unsorted permissions' => [static function (array &$payload): void {
            $payload['permissions']['view'] = ['reports.z', 'reports.a'];
        }];
        yield 'manage permission' => [static function (array &$payload): void {
            $payload['permissions']['run'] = ['reports.manage'];
        }];
        yield 'hyphenated manage permission' => [static function (array &$payload): void {
            $payload['permissions']['run'] = ['reports-manage'];
        }];
        yield 'unknown export assertion result' => [static function (array &$payload): void {
            $payload['export_contracts'][0]['assertion_codes'][0] = 'export.xlsx.schema.skipped';
        }];
        yield 'duplicate component class' => [static function (array &$payload): void {
            $payload['components'][] = $payload['components'][0];
        }];
        yield 'duplicate component class with another hash' => [static function (array &$payload): void {
            $payload['components'][] = [
                'class' => $payload['components'][0]['class'],
                'sha256' => str_repeat('f', 64),
            ];
        }];
        yield 'unsorted component classes' => [static function (array &$payload): void {
            [$payload['components'][0], $payload['components'][1]] = [
                $payload['components'][1],
                $payload['components'][0],
            ];
        }];
        yield 'export assertion for another format' => [static function (array &$payload): void {
            $payload['export_contracts'][0]['assertion_codes'][0] = 'export.csv.fixture.passed';
        }];
        yield 'unsorted assertion codes' => [static function (array &$payload): void {
            $payload['source']['assertion_codes'] = [
                'source.snapshot.passed',
                'source.identity.passed',
            ];
        }];
        yield 'non-canonical required checks' => [static function (array &$payload): void {
            $payload['ci']['required_checks'] = ['rbac_contract', 'binding_contract'];
        }];
    }

    public static function payload(): array
    {
        return [
            'code' => 'project_portfolio_health',
            'candidate_manifest_sha256' => str_repeat('1', 64),
            'candidate_definition_sha256' => str_repeat('2', 64),
            'binding_sha256' => str_repeat('3', 64),
            'contract_version' => '1.0.0',
            'versions' => [
                'source_schema' => '1.0.0',
                'formula' => '1.0.0',
                'contract' => '1.0.0',
                'renderer' => '1.0.0',
            ],
            'semantic_fingerprints' => [
                'source' => str_repeat('4', 64),
                'formula' => str_repeat('5', 64),
            ],
            'fixture_sha256' => str_repeat('6', 64),
            'conformance_evidence_sha256' => str_repeat('7', 64),
            'source' => [
                'snapshot_kind' => 'sealed_snapshot',
                'snapshot_id' => 'snapshot-1',
                'source_sha256' => str_repeat('8', 64),
                'rows_sha256' => str_repeat('9', 64),
                'row_count' => 1,
                'assertion_codes' => ['source.identity.passed'],
            ],
            'formula' => [
                'formula_version' => '1.0.0',
                'totals_sha256' => str_repeat('a', 64),
                'assertion_codes' => ['formula.identity.passed'],
            ],
            'components' => [
                ['class' => 'Tests\\Support\\Reporting\\CatalogTestDataProvider', 'sha256' => str_repeat('b', 64)],
                ['class' => 'Tests\\Support\\Reporting\\CatalogTestDrillDownProvider', 'sha256' => str_repeat('c', 64)],
                ['class' => 'Tests\\Support\\Reporting\\CatalogTestRowQuery', 'sha256' => str_repeat('d', 64)],
            ],
            'permissions' => [
                'view' => ['budgeting.portfolio_dashboard.view'],
                'run' => ['budgeting.portfolio_dashboard.view'],
                'export' => ['budgeting.portfolio_dashboard.export'],
                'download' => ['budgeting.portfolio_dashboard.export'],
                'sensitive' => [],
                'audit' => [],
            ],
            'export_contracts' => [[
                'format' => 'xlsx',
                'schema_sha256' => str_repeat('e', 64),
                'fixture_sha256' => str_repeat('f', 64),
                'renderer_class' => 'App\\BusinessModules\\Core\\Reporting\\Infrastructure\\Exports\\XlsxReportExportRenderer',
                'renderer_contract_sha256' => str_repeat('d', 64),
                'renderer_sha256' => str_repeat('1', 64),
                'assertion_codes' => [
                    'export.xlsx.fixture.passed',
                    'export.xlsx.provenance.passed',
                    'export.xlsx.redaction.passed',
                    'export.xlsx.renderer.passed',
                    'export.xlsx.schema.passed',
                ],
            ]],
            'drill_down_contract' => [
                'schema_sha256' => str_repeat('2', 64),
                'assertion_codes' => ['drill_down.schema.passed'],
            ],
            'ci' => [
                'run_id' => 'ci-1001',
                'commit_sha' => str_repeat('a', 40),
                'suite_sha256' => str_repeat('3', 64),
                'completed_at_utc' => '2026-08-01T01:02:03.123456Z',
                'required_checks' => [
                    'binding_contract',
                    'drill_down_contract',
                    'export_xlsx_contract',
                    'formula_contract',
                    'postgresql_contract',
                    'rbac_contract',
                    'source_contract',
                ],
            ],
            'release' => [
                'git_sha' => str_repeat('a', 40),
                'created_at_utc' => '2026-08-01T02:03:04.654321Z',
                'approver_identity' => 'release-bot@most',
            ],
        ];
    }
}
