<?php

declare(strict_types=1);

use App\BusinessModules\Core\Reporting\Application\Publication\ReportCatalogActivationInputBundleBuilder;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function activationInputOptions(array $argv): array
{
    $allowed = ['plan-2', 'plan-3-candidate', 'plan-3-evidence', 'release-sha', 'generated-at', 'output'];
    $options = [];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--check') {
            if (isset($options['check'])) throw new RuntimeException('report_catalog_activation_inputs_option_invalid');
            $options['check'] = true; continue;
        }
        if (preg_match('/^--([a-z0-9-]+)=(.+)$/D', $arg, $m) !== 1 || ! in_array($m[1], $allowed, true) || isset($options[$m[1]])) {
            throw new RuntimeException('report_catalog_activation_inputs_option_invalid');
        }
        $options[$m[1]] = $m[2];
    }
    foreach ($allowed as $option) if (! isset($options[$option])) throw new RuntimeException('report_catalog_activation_inputs_option_missing');
    return $options + ['check' => false];
}

try {
    $options = activationInputOptions($argv);
    $root = (string) realpath(dirname(__DIR__, 2));
    $paths = ['plan_2' => $options['plan-2'], 'plan_3_candidate' => $options['plan-3-candidate'], 'plan_3_evidence' => $options['plan-3-evidence']];
    $bundle = (new ReportCatalogActivationInputBundleBuilder($root, $paths))->build($options['release-sha'], new DateTimeImmutable($options['generated-at']));
    $document = ['artifact_id' => $bundle->artifactId, 'schema_version' => '1.0.0', 'status' => $bundle->status, 'release_sha' => $bundle->releaseSha, 'generated_at' => $bundle->generatedAt->format('Y-m-d\\TH:i:s\\Z'), 'source_artifacts' => $bundle->sourceArtifacts, 'candidate_manifest' => $bundle->candidateManifest, 'candidate_payloads' => $bundle->candidatePayloads, 'validation_items' => $bundle->validationItems, 'bindings' => $bundle->bindings, 'conformance_records' => $bundle->conformanceRecords, 'plan_evidence_documents' => $bundle->planEvidenceDocuments, 'counts' => $bundle->counts, 'section_hashes' => $bundle->sectionHashes];
    $bytes = CanonicalJson::encode($document)."\n";
    $output = $root.DIRECTORY_SEPARATOR.$options['output'];
    if ($options['check']) {
        if (! is_file($output) || ! hash_equals((string) file_get_contents($output), $bytes)) throw new RuntimeException('report_catalog_activation_inputs_check_failed');
    } else {
        if (! is_dir(dirname($output))) throw new RuntimeException('report_catalog_activation_inputs_output_invalid');
        $temporary = $output.'.'.bin2hex(random_bytes(8)).'.tmp';
        file_put_contents($temporary, $bytes, LOCK_EX);
        if (! rename($temporary, $output)) throw new RuntimeException('report_catalog_activation_inputs_output_failed');
    }
    fwrite(STDOUT, 'report-catalog-activation-inputs: activation_inputs_passed 12+16=28 sha256='.hash('sha256', $bytes).PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDERR, "quality-gate:invalid\n");
    exit(2);
}
