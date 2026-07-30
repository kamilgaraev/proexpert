<?php

declare(strict_types=1);

use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneCPlatformEvidenceBuilder;
use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneCPrerequisiteEvidenceValidator;
use App\BusinessModules\Core\Reporting\Infrastructure\Evidence\GitTrackedRepositoryFileReader;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;

require dirname(__DIR__, 2).'/vendor/autoload.php';

/** @return array<string, string> */
function arguments(array $argv): array
{
    $required = [
        'repository-root', 'commit-sha', 'plan-1b-path', 'plan-1c-path', 'prerequisite-bundle',
        'platform-quality-artifact', 'ci-workspace-artifact', 'ci-saved-views-artifact',
        'ci-subscriptions-artifact', 'ci-integration-artifact', 'ci-fake-sequence-artifact', 'executed-at',
    ];
    $parsed = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) throw new RuntimeException('plan_one_c_platform_evidence_arguments_invalid');
        [$name, $value] = explode('=', substr($argument, 2), 2);
        if (!in_array($name, $required, true) || $value === '' || isset($parsed[$name])) throw new RuntimeException('plan_one_c_platform_evidence_arguments_invalid');
        $parsed[$name] = $value;
    }
    if (array_keys($parsed) !== $required) throw new RuntimeException('plan_one_c_platform_evidence_arguments_invalid');
    return $parsed;
}

/** @return array{document:array<string, mixed>, sha256:string} */
function jsonDocument(string $path): array
{
    $bytes = file_get_contents($path);
    if (!is_string($bytes)) throw new RuntimeException('plan_one_c_platform_evidence_input_missing');
    $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value) || array_is_list($value)) throw new RuntimeException('plan_one_c_platform_evidence_input_invalid');
    if (!hash_equals(CanonicalJson::encode($value)."\n", $bytes)) throw new RuntimeException('plan_one_c_platform_evidence_input_invalid');
    return ['document' => $value, 'sha256' => hash('sha256', $bytes)];
}

/** @return array{platform_quality:array<string,mixed>,ci_artifacts:array<string,array<string,mixed>>,published_count:int,binding_count:int,unresolved_risks:list<mixed>} */
function platformEvidence(array $args, string $commit): array
{
    $quality = jsonDocument($args['platform-quality-artifact']);
    $ciInputs = [
        'workspace' => 'ci-workspace-artifact', 'saved_views' => 'ci-saved-views-artifact',
        'subscriptions' => 'ci-subscriptions-artifact', 'integration' => 'ci-integration-artifact',
        'fake_sequence' => 'ci-fake-sequence-artifact',
    ];
    $documents = ['platform_quality' => $quality['document']];
    foreach ($ciInputs as $name => $argument) {
        $input = jsonDocument($args[$argument]);
        $document = $input['document'];
        if (($document['artifact_id'] ?? null) !== 'reporting_ci_'.$name || ($document['status'] ?? null) !== 'passed'
            || ($document['repository_commit'] ?? null) !== $commit || !is_array($document['command_record'] ?? null)) {
            throw new RuntimeException('plan_one_c_platform_evidence_input_invalid');
        }
        $documents[$name] = $document;
    }
    if (($quality['document']['artifact_id'] ?? null) !== 'report_quality_evidence'
        || ($quality['document']['status'] ?? null) !== 'platform_passed'
        || ($quality['document']['release_sha'] ?? null) !== $commit) {
        throw new RuntimeException('plan_one_c_platform_evidence_input_invalid');
    }
    $counts = null;
    foreach ($documents as $name => $document) {
        if ($name === 'platform_quality') {
            continue;
        }
        $current = [$document['published_count'] ?? null, $document['binding_count'] ?? null, $document['unresolved_risks'] ?? null];
        if (!is_int($current[0]) || !is_int($current[1]) || !is_array($current[2]) || $current[0] !== $current[1] || $current[2] !== []) {
            throw new RuntimeException('plan_one_c_platform_evidence_input_invalid');
        }
        if ($counts !== null && $counts !== $current) throw new RuntimeException('plan_one_c_platform_evidence_input_invalid');
        $counts = $current;
    }
    if ($counts === null) throw new RuntimeException('plan_one_c_platform_evidence_input_invalid');
    unset($documents['platform_quality']);
    return ['platform_quality' => $quality['document'], 'ci_artifacts' => $documents, 'published_count' => $counts[0], 'binding_count' => $counts[1], 'unresolved_risks' => $counts[2]];
}

try {
    $args = arguments($argv);
    $root = realpath($args['repository-root']);
    if (!is_string($root)) throw new RuntimeException('plan_one_c_platform_evidence_root_invalid');
    $validator = new PlanOneCPrerequisiteEvidenceValidator($root);
    $bundle = $validator->validateBundle($args['prerequisite-bundle']);
    $reader = new GitTrackedRepositoryFileReader();
    $planOneB = $reader->read($root, $args['plan-1b-path'], $args['commit-sha']);
    $planOneC = $reader->read($root, $args['plan-1c-path'], $args['commit-sha']);
    $builder = new PlanOneCPlatformEvidenceBuilder($root);
    $document = $builder->build($bundle, $planOneB, $planOneC, $args['commit-sha'], new DateTimeImmutable($args['executed-at']), platformEvidence($args, $args['commit-sha']));
    $outputPath = $root.'/build/reports/plan-1c-platform-completion.json';
    $builder->publish($outputPath, $document);
    $bytes = file_get_contents($outputPath);
    if (!is_string($bytes)) throw new RuntimeException('plan_one_c_platform_evidence_reread_failed');
    fwrite(STDOUT, hash('sha256', $bytes).PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}
