<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Cycle;

use App\BusinessModules\Core\Reporting\Application\Conformance\ReportConformanceDrillExpectation;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotWrite;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSourceSnapshotStatus;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence\R15CiFixtureSnapshotStore;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence\R15CiConformanceEvidenceGenerator;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence\R15CiEvidenceRuntimeGuard;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence\R15CiFixtureDrillExpectationResolver;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\R15CiRuntimeFixtureFactory;

final class R15CiConformanceEvidenceGeneratorE2eTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('APP_ENV=testing');
        putenv('MOST_R15_CI_EVIDENCE=1');
        putenv('GITHUB_ACTIONS=true');
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        putenv('MOST_R15_CI_EVIDENCE');
        putenv('GITHUB_ACTIONS');
        parent::tearDown();
    }

    public function test_generator_runs_real_procurement_adapter_against_sealed_fixture(): void
    {
        $scenario = (new R15CiRuntimeFixtureFactory)->build();
        $expected = new ReportConformanceDrillExpectation(
            $scenario['fixture']->fixtureHash,
            $scenario['drillCell'],
            new Sha256Hash(hash('sha256', CanonicalJson::encode([
                'next_cursor' => $scenario['drillResult']->nextCursor,
                'resource_links' => [],
                'rows' => $scenario['drillResult']->rows,
            ]))),
        );
        $artifact = (new R15CiConformanceEvidenceGenerator(
            R15CiEvidenceRuntimeGuard::ciComposition(),
            new R15CiFixtureDrillExpectationResolver($expected),
        ))->generate(
            $scenario['candidate'],
            $scenario['binding'],
            $scenario['context'],
            $scenario['query'],
            $scenario['fixture'],
            str_repeat('1', 40),
            new DateTimeImmutable('2026-08-01T00:00:00+00:00'),
        );

        self::assertTrue($artifact->evidence->passed());
        self::assertSame('passed', $artifact->canonicalPayload()['status']);
    }

    public function test_generator_rejects_missing_or_tampered_drill_evidence(): void
    {
        $scenario = (new R15CiRuntimeFixtureFactory)->build();
        $generator = new R15CiConformanceEvidenceGenerator(
            R15CiEvidenceRuntimeGuard::ciComposition(),
            new R15CiFixtureDrillExpectationResolver(new ReportConformanceDrillExpectation(
                $scenario['fixture']->fixtureHash,
                $scenario['drillCell'],
                new Sha256Hash(str_repeat('e', 64)),
            )),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('r15_ci_evidence_conformance_failed');
        $generator->generate(
            $scenario['candidate'], $scenario['binding'], $scenario['context'], $scenario['query'],
            $scenario['fixture'], str_repeat('1', 40), new DateTimeImmutable('2026-08-01T00:00:00+00:00'),
        );
    }

    public function test_fixture_store_rejects_tampered_row_and_snapshot_hash(): void
    {
        $scenario = (new R15CiRuntimeFixtureFactory)->build();
        $header = $scenario['write']->header;
        $tampered = new ReportSourceSnapshotWrite(
            new ReportSourceSnapshotHeader(
                $header->id, $header->sourceKind, $header->reportCode, $header->schemaVersion,
                $header->scope, $header->queryHash, $header->asOf, $header->sourceHash, $header->watermarks,
                $header->generatedAt, $header->staleAt, ReportSourceSnapshotStatus::WRITING,
                $header->rowCount, $header->drillRowCount, new Sha256Hash(str_repeat('f', 64)), null, null,
                $header->reportQueryIdentity, $header->reportQueryHash,
            ),
            $scenario['write']->rows,
            $scenario['write']->drillRows,
        );

        $this->expectException(\App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException::class);
        new R15CiFixtureSnapshotStore($tampered);
    }
}
