<?php

declare(strict_types=1);

use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneCPlatformEvidenceBuilder;
use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneCPrerequisiteEvidenceValidator;
use App\BusinessModules\Core\Reporting\Infrastructure\Evidence\GitTrackedRepositoryFileReader;

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

/** @return array<string, mixed> */
function jsonDocument(string $path): array
{
    $bytes = file_get_contents($path);
    if (!is_string($bytes)) throw new RuntimeException('plan_one_c_platform_evidence_input_missing');
    $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value) || array_is_list($value)) throw new RuntimeException('plan_one_c_platform_evidence_input_invalid');
    return $value;
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
    foreach (['platform-quality-artifact', 'ci-workspace-artifact', 'ci-saved-views-artifact', 'ci-subscriptions-artifact', 'ci-integration-artifact', 'ci-fake-sequence-artifact'] as $name) {
        $input = jsonDocument($args[$name]);
        if (($input['status'] ?? null) !== 'passed' && ($input['status'] ?? null) !== 'platform_passed') throw new RuntimeException('plan_one_c_platform_evidence_input_invalid');
    }
    $builder = new PlanOneCPlatformEvidenceBuilder($root);
    $document = $builder->build($bundle, $planOneB, $planOneC, $args['commit-sha'], new DateTimeImmutable($args['executed-at']), ['published_count' => 0, 'binding_count' => 0, 'unresolved_risks' => []]);
    $outputPath = $root.'/build/reports/plan-1c-platform-completion.json';
    $builder->publish($outputPath, $document);
    $bytes = file_get_contents($outputPath);
    if (!is_string($bytes)) throw new RuntimeException('plan_one_c_platform_evidence_reread_failed');
    fwrite(STDOUT, hash('sha256', $bytes).PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}
