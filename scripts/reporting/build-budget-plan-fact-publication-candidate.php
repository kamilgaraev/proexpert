<?php

declare(strict_types=1);

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactPublicationCandidateArtifactBuilder;

require dirname(__DIR__, 2).'/vendor/autoload.php';

const BUDGET_PLAN_FACT_OUTPUT_DIRECTORY = 'build/reports/publication-candidates/budget_plan_fact/v1';

try {
    $options = getopt('', ['candidate:', 'conformance:', 'proof:', 'commit-sha:']);
    if (count($options) !== 4 || $argc !== 5) {
        throw new InvalidArgumentException('budget_plan_fact_candidate_artifact_input_invalid');
    }
    $root = dirname(__DIR__, 2);
    (new BudgetPlanFactPublicationCandidateArtifactBuilder)->build(
        $root.'/'.BUDGET_PLAN_FACT_OUTPUT_DIRECTORY,
        (string) $options['commit-sha'],
        document((string) $options['candidate']),
        document((string) $options['conformance']),
        document((string) $options['proof']),
    );
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}

/** @return array<string, mixed> */
function document(string $path): array
{
    $realPath = realpath($path);
    $bytes = is_string($realPath) && ! is_link($path) && is_file($realPath) ? file_get_contents($realPath) : false;
    try {
        $document = is_string($bytes) ? json_decode($bytes, true, 64, JSON_THROW_ON_ERROR) : null;
    } catch (JsonException) {
        throw new InvalidArgumentException('budget_plan_fact_candidate_artifact_input_invalid');
    }
    if (! is_array($document) || array_is_list($document) || ! hash_equals(CanonicalJson::encode($document), (string) $bytes)) {
        throw new InvalidArgumentException('budget_plan_fact_candidate_artifact_input_invalid');
    }

    return $document;
}
