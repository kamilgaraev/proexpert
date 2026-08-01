<?php

declare(strict_types=1);

use App\BusinessModules\Core\Reporting\Application\Publication\Ed25519ReportPublicationReleaseArtifactSigner;
use App\BusinessModules\Core\Reporting\Application\Publication\ProjectReportPublicationReleaseArtifactVerifierFactory;
use App\BusinessModules\Core\Reporting\Application\Publication\ProjectReportPublicationReleaseRequestRegistry;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseArtifactIssuer;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseBundleWriter;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseRequestFileLoader;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence\ProcurementCycleReleaseCandidateResolver;

require dirname(__DIR__).'/vendor/autoload.php';

$options = getopt('', [
    'output-directory:',
    'request:',
]);

try {
    foreach (['output-directory', 'request'] as $key) {
        if (! is_string($options[$key] ?? null) || $options[$key] === '') {
            throw new RuntimeException('report_publication_release_input_invalid');
        }
    }
    $requestRoot = dirname(__DIR__).'/build/reports/publication-release-requests';
    $request = (new ReportPublicationReleaseRequestFileLoader)->load($options['request'], $requestRoot);

    $trustedRoot = getenv('MOST_R15_RELEASE_TRUSTED_ROOT');
    if (! is_string($trustedRoot) || $trustedRoot === '') {
        throw new RuntimeException('report_publication_release_trusted_root_missing');
    }
    $trustedRootReal = realpath($trustedRoot);
    if (! is_string($trustedRootReal) || is_link($trustedRoot) || ! is_dir($trustedRootReal)) {
        throw new RuntimeException('report_publication_release_trusted_root_untrusted');
    }
    foreach ([
        'r15-candidate-manifest.json',
        'r15-conformance-evidence.json',
        'r15-proof-template.json',
        'r15_release_request.json',
    ] as $requiredFile) {
        $path = $trustedRootReal.DIRECTORY_SEPARATOR.$requiredFile;
        if (is_link($path) || ! is_file($path) || realpath($path) !== $path) {
            throw new RuntimeException('report_publication_release_trusted_root_incomplete');
        }
    }

    (new ProcurementCycleReleaseCandidateResolver)->resolve($trustedRootReal, $request->commitSha);
    throw new RuntimeException('report_publication_release_composition_not_wired');
    $resolvedRequest->admission->assertProductionSafe();
    $repository = getenv('GITHUB_REPOSITORY');
    $workflowRef = getenv('GITHUB_WORKFLOW_REF');
    if (! is_string($repository) || ! is_string($workflowRef)) {
        throw new RuntimeException('report_publication_release_context_untrusted');
    }
    $workflowPrefix = $repository.'/';
    if (! str_starts_with($workflowRef, $workflowPrefix)) {
        throw new RuntimeException('report_publication_release_context_untrusted');
    }
    $secretKey = base64_decode((string) getenv('REPORT_PUBLICATION_RELEASE_SECRET_KEY_BASE64'), true);
    if (! is_string($secretKey)) {
        throw new RuntimeException('report_publication_release_signing_key_invalid');
    }
    $completedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    $issuer = new ReportPublicationReleaseArtifactIssuer(
        new Ed25519ReportPublicationReleaseArtifactSigner(
            'most-ci',
            'reports-release-2026-01',
            $secretKey,
        ),
        (new ProjectReportPublicationReleaseArtifactVerifierFactory)->create(),
        $resolvedRequest->gate,
    );
    $bundle = $issuer->issue(
        $resolvedRequest->admission,
        [
            'actor_identity' => 'github:'.(string) getenv('GITHUB_ACTOR_ID'),
            'commit_sha' => (string) getenv('GITHUB_SHA'),
            'completed_at_utc' => $completedAt,
            'environment' => (string) getenv('REPORT_PUBLICATION_RELEASE_ENVIRONMENT'),
            'event_name' => (string) getenv('GITHUB_EVENT_NAME'),
            'job' => (string) getenv('GITHUB_JOB'),
            'ref' => (string) getenv('GITHUB_REF'),
            'repository' => $repository,
            'run_attempt' => filter_var(getenv('GITHUB_RUN_ATTEMPT'), FILTER_VALIDATE_INT),
            'run_id' => (string) getenv('GITHUB_RUN_ID'),
            'workflow_ref' => substr($workflowRef, strlen($workflowPrefix)),
        ],
    );
    (new ReportPublicationReleaseBundleWriter)->write($bundle, $options['output-directory']);
    fwrite(STDOUT, $bundle->artifactName.PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}
