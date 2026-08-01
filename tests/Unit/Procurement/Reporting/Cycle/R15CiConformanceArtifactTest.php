<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Cycle;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFormulaConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence\R15CiConformanceArtifact;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence\R15CiEvidenceRuntimeGuard;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;

final class R15CiConformanceArtifactTest extends TestCase
{
    public function test_artifact_contains_the_complete_canonical_evidence_and_detects_tampering_by_digest(): void
    {
        $artifact = new R15CiConformanceArtifact($this->evidence());
        $document = $artifact->canonicalPayload();

        self::assertSame('passed', $document['status']);
        self::assertSame($this->evidence()->canonicalPayload(), $document['conformance']);
        self::assertSame($this->evidence()->digest()->value, $document['conformance_digest']);
        $document['conformance']['source']['row_count'] = 99;
        self::assertNotSame(
            hash('sha256', \App\BusinessModules\Core\Reporting\Support\CanonicalJson::encode($document['conformance'])),
            $artifact->canonicalPayload()['conformance_digest'],
        );
    }

    public function test_ci_runtime_guard_rejects_missing_explicit_ci_boundary(): void
    {
        putenv('MOST_R15_CI_EVIDENCE');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('r15_ci_evidence_runtime_forbidden');
        (new R15CiEvidenceRuntimeGuard)->assertEnabled();
    }

    public function test_ci_runtime_guard_rejects_production_composition_even_when_ci_flag_is_set(): void
    {
        putenv('MOST_R15_CI_EVIDENCE=1');
        putenv('GITHUB_ACTIONS=true');
        putenv('APP_ENV=production');
        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('r15_ci_evidence_runtime_forbidden');
            (new R15CiEvidenceRuntimeGuard)->assertEnabled();
        } finally {
            putenv('APP_ENV');
            putenv('GITHUB_ACTIONS');
            putenv('MOST_R15_CI_EVIDENCE');
        }
    }

    private function evidence(): ReportDefinitionConformanceEvidence
    {
        $hash = new Sha256Hash(str_repeat('a', 64));
        return new ReportDefinitionConformanceEvidence(
            'procurement_cycle',
            $hash,
            '1.0.0',
            '1.0.0',
            new Sha256Hash(str_repeat('b', 64)),
            new ReportSourceConformanceEvidence($hash, 'procurement.cycle', 'fixture-1', 1, $hash, true, ['source.runtime.passed']),
            new ReportFormulaConformanceEvidence('procurement-cycle.v1', $hash, true, ['formula.runtime.passed']),
            ['App\\Fixture' => $hash],
            2,
            'passed',
            str_repeat('c', 40),
            new DateTimeImmutable('2026-08-01T00:00:00+00:00'),
        );
    }
}
