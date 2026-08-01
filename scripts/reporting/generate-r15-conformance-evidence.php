<?php

declare(strict_types=1);

use App\BusinessModules\Core\Reporting\Application\Conformance\ReportConformanceDrillExpectation;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence\R15CiConformanceEvidenceGenerator;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence\R15CiEvidenceRuntimeGuard;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence\R15CiFixtureDrillExpectationResolver;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__, 2).'/tests/Support/Reporting/ReportDefinitionBuilder.php';
require dirname(__DIR__, 2).'/tests/Support/Reporting/ReportExecutionContextBuilder.php';
require dirname(__DIR__, 2).'/tests/Support/Reporting/R15CiRuntimeFixtureFactory.php';

if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('MOST_R15_CI_EVIDENCE') !== '1') {
    fwrite(STDERR, "r15_ci_evidence_runtime_forbidden\n");
    exit(1);
}

$commitSha = getenv('GITHUB_SHA');
if (! is_string($commitSha) || preg_match('/^[a-f0-9]{40}$/D', $commitSha) !== 1) {
    fwrite(STDERR, "r15_ci_evidence_commit_invalid\n");
    exit(1);
}

$scenario = (new Tests\Support\Reporting\R15CiRuntimeFixtureFactory)->build();
$drillHash = new Sha256Hash(hash('sha256', CanonicalJson::encode([
    'next_cursor' => $scenario['drillResult']->nextCursor,
    'resource_links' => [],
    'rows' => $scenario['drillResult']->rows,
])));
$artifact = (new R15CiConformanceEvidenceGenerator(
    R15CiEvidenceRuntimeGuard::ciComposition(),
    new R15CiFixtureDrillExpectationResolver(new ReportConformanceDrillExpectation(
        $scenario['fixture']->fixtureHash,
        $scenario['drillCell'],
        $drillHash,
    )),
))->generate(
    $scenario['candidate'],
    $scenario['binding'],
    $scenario['context'],
    $scenario['query'],
    $scenario['fixture'],
    $commitSha,
    new DateTimeImmutable('now', new DateTimeZone('UTC')),
);

if (! $artifact->evidence->passed()) {
    fwrite(STDERR, "r15_ci_evidence_conformance_failed\n");
    exit(1);
}

echo $artifact->canonicalJson(), PHP_EOL;
