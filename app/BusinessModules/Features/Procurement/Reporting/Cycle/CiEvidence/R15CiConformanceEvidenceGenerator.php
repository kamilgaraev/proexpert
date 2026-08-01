<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence;

use App\BusinessModules\Core\Reporting\Application\Conformance\ReportConformanceDrillExpectationResolver;
use App\BusinessModules\Core\Reporting\Application\Conformance\ReportSourceConformanceHarness;
use App\BusinessModules\Core\Reporting\Domain\DTO\CandidateReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportConformanceFixture;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class R15CiConformanceEvidenceGenerator
{
    public function __construct(
        private R15CiEvidenceRuntimeGuard $guard,
        private ReportConformanceDrillExpectationResolver $drillExpectations,
    ) {}

    public function generate(
        CandidateReportDefinition $candidate,
        ReportDefinitionBinding $binding,
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportConformanceFixture $fixture,
        string $commitSha,
        DateTimeImmutable $generatedAt,
    ): R15CiConformanceArtifact {
        $this->guard->assertEnabled();
        if ($candidate->code !== 'procurement_cycle'
            || ! hash_equals($candidate->code, $query->definition->code)
            || ! hash_equals($candidate->definitionHash->value, $query->definition->definitionHash->value)) {
            throw new InvalidArgumentException('r15_ci_evidence_identity_invalid');
        }

        $evidence = (new ReportSourceConformanceHarness($this->drillExpectations))->verify(
            $candidate,
            $binding,
            $context,
            $query,
            $fixture,
            $commitSha,
            $generatedAt,
        );
        if (! $evidence->passed()) {
            throw new InvalidArgumentException('r15_ci_evidence_conformance_failed');
        }

        return new R15CiConformanceArtifact($evidence);
    }
}
