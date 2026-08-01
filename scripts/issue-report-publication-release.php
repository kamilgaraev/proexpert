<?php

declare(strict_types=1);

use App\BusinessModules\Core\Reporting\Application\Publication\Ed25519ReportPublicationReleaseArtifactSigner;
use App\BusinessModules\Core\Reporting\Application\Publication\ProjectReportPublicationReleaseArtifactVerifierFactory;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseArtifactIssuer;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;

require dirname(__DIR__).'/vendor/autoload.php';

$options = getopt('', [
    'candidate-manifest:',
    'official-manifest:',
    'output-directory:',
    'proof-template:',
]);

try {
    foreach (['candidate-manifest', 'official-manifest', 'output-directory', 'proof-template'] as $key) {
        if (! is_string($options[$key] ?? null) || $options[$key] === '') {
            throw new RuntimeException('report_publication_release_input_invalid');
        }
    }
    $proofBytes = file_get_contents($options['proof-template']);
    $candidateBytes = file_get_contents($options['candidate-manifest']);
    $officialBytes = file_get_contents($options['official-manifest']);
    if (! is_string($proofBytes) || ! is_string($candidateBytes) || ! is_string($officialBytes)) {
        throw new RuntimeException('report_publication_release_input_invalid');
    }
    $proofTemplate = json_decode($proofBytes, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($proofTemplate)) {
        throw new RuntimeException('report_publication_release_input_invalid');
    }
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
    );
    $bundle = $issuer->issue(
        $proofTemplate,
        new Sha256Hash(hash('sha256', $candidateBytes)),
        new Sha256Hash(hash('sha256', $officialBytes)),
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
    $outputDirectory = $options['output-directory'];
    if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0700, true) && ! is_dir($outputDirectory)) {
        throw new RuntimeException('report_publication_release_output_unavailable');
    }
    $proofPath = $outputDirectory.DIRECTORY_SEPARATOR.$bundle->artifactName.'.proof.json';
    $artifactPath = $outputDirectory.DIRECTORY_SEPARATOR.$bundle->artifactName.'.json';
    if (file_put_contents($proofPath, $bundle->proof->canonicalBytes()) === false
        || file_put_contents($artifactPath, $bundle->artifactBytes) === false) {
        throw new RuntimeException('report_publication_release_output_unavailable');
    }
    fwrite(STDOUT, $bundle->artifactName.PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}
