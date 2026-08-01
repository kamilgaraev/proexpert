<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

final class TaskSevenCliFailure extends RuntimeException
{
}

final class TaskSevenGitFailure extends RuntimeException
{
}

final class TaskSevenContractFailure extends RuntimeException
{
}

function failContract(bool $condition, string $code): void
{
    if (!$condition) {
        throw new TaskSevenContractFailure($code);
    }
}

function runGit(array $arguments): string
{
    $process = new Process(['git', ...$arguments], getcwd());
    $process->run();
    if (!$process->isSuccessful()) {
        throw new TaskSevenGitFailure('TASK_7_GIT_COMMAND_FAILED');
    }

    return $process->getOutput();
}

function gitDocument(string $commit, string $path): string
{
    try {
        return runGit(['show', $commit.':'.$path]);
    } catch (TaskSevenGitFailure $failure) {
        throw new TaskSevenGitFailure('TASK_7_GIT_DOCUMENT_READ_FAILED', 0, $failure);
    }
}

function decodeDocument(string $bytes): array
{
    try {
        $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new TaskSevenGitFailure('TASK_7_JSON_INVALID', 0, $exception);
    }
    if (!is_array($value)) {
        throw new TaskSevenGitFailure('TASK_7_JSON_INVALID');
    }

    return $value;
}

function isListArray(array $value): bool
{
    return array_is_list($value);
}

function canonicalize(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (isListArray($value)) {
        return array_map('canonicalize', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = canonicalize($item);
    }

    return $value;
}

function canonicalJson(mixed $value): string
{
    return json_encode(canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function packageMap(mixed $packages, string $invalidCode): array
{
    failContract(is_array($packages) && array_is_list($packages), $invalidCode);
    $map = [];
    foreach ($packages as $package) {
        failContract(is_array($package) && isset($package['name']) && is_string($package['name']), $invalidCode);
        failContract(!array_key_exists($package['name'], $map), $invalidCode);
        $map[$package['name']] = $package;
    }
    ksort($map, SORT_STRING);

    return $map;
}

function composerContentHash(array $composer): string
{
    $relevant = [];
    foreach (['name', 'version', 'require', 'require-dev', 'conflict', 'replace', 'provide', 'minimum-stability', 'prefer-stable', 'repositories', 'extra'] as $key) {
        if (array_key_exists($key, $composer)) {
            $relevant[$key] = $composer[$key];
        }
    }
    if (isset($composer['config']['platform'])) {
        $relevant['config']['platform'] = $composer['config']['platform'];
    }
    ksort($relevant, SORT_STRING);

    return hash('md5', json_encode($relevant, JSON_THROW_ON_ERROR, 512));
}

function dependencyPath(string $path): bool
{
    return preg_match(
        '~(^|/)(composer\.json|composer\.lock|symfony\.lock|package\.json|package-lock\.json|pnpm-lock\.yaml|yarn\.lock|bun\.lock|bun\.lockb|\.npmrc|\.yarnrc|\.yarnrc\.yml|phpunit\.xml|phpunit\.xml\.dist|phpstan\.neon|phpstan\.neon\.dist|pint\.json)$~D',
        str_replace('\\', '/', $path),
    ) === 1;
}

function parseArguments(array $arguments): array
{
    $values = [];
    foreach ($arguments as $argument) {
        if ($argument === '--check') {
            if (isset($values['check'])) {
                throw new TaskSevenCliFailure('TASK_7_CLI_INVALID');
            }
            $values['check'] = true;
            continue;
        }
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new TaskSevenCliFailure('TASK_7_CLI_INVALID');
        }
        [$key, $value] = explode('=', substr($argument, 2), 2);
        if (!in_array($key, ['baseline-commit', 'reviewed-commit', 'expected-composer-json-sha256', 'expected-composer-lock-sha256', 'output'], true) || array_key_exists($key, $values)) {
            throw new TaskSevenCliFailure('TASK_7_CLI_INVALID');
        }
        $values[$key] = $value;
    }

    foreach (['baseline-commit', 'reviewed-commit', 'expected-composer-json-sha256', 'expected-composer-lock-sha256'] as $key) {
        if (!isset($values[$key]) || !is_string($values[$key])) {
            throw new TaskSevenCliFailure('TASK_7_CLI_INVALID');
        }
    }
    if (isset($values['check']) === isset($values['output'])) {
        throw new TaskSevenCliFailure('TASK_7_CLI_INVALID');
    }
    if (preg_match('/^[a-f0-9]{40}$/D', $values['baseline-commit']) !== 1 || preg_match('/^[a-f0-9]{40}$/D', $values['reviewed-commit']) !== 1) {
        throw new TaskSevenCliFailure('TASK_7_CLI_INVALID');
    }
    if (preg_match('/^[a-f0-9]{64}$/D', $values['expected-composer-json-sha256']) !== 1 || preg_match('/^[a-f0-9]{64}$/D', $values['expected-composer-lock-sha256']) !== 1) {
        throw new TaskSevenCliFailure('TASK_7_CLI_INVALID');
    }
    if (isset($values['output']) && $values['output'] !== 'build/reports/task-7-composer-evidence.json') {
        throw new TaskSevenCliFailure('TASK_7_OUTPUT_PATH_INVALID');
    }

    return $values;
}

function resolveOutputPath(array $arguments): array
{
    if (!isset($arguments['output'])) {
        return $arguments;
    }

    $repositoryRoot = rtrim(trim(runGit(['rev-parse', '--show-toplevel'])), "/\\");
    $resolvedRoot = realpath($repositoryRoot);
    $outputDirectory = $repositoryRoot.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'reports';
    $resolvedDirectory = realpath($outputDirectory);
    if ($resolvedRoot === false
        || $resolvedDirectory === false
        || !is_dir($resolvedDirectory)
        || !is_writable($resolvedDirectory)
        || strcasecmp(dirname($resolvedDirectory), $resolvedRoot.DIRECTORY_SEPARATOR.'build') !== 0
        || strcasecmp($resolvedDirectory, $resolvedRoot.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'reports') !== 0) {
        throw new TaskSevenCliFailure('TASK_7_OUTPUT_PATH_INVALID');
    }

    $outputPath = $resolvedDirectory.DIRECTORY_SEPARATOR.'task-7-composer-evidence.json';
    if (is_link($outputPath) || (file_exists($outputPath) && !is_file($outputPath))) {
        throw new TaskSevenCliFailure('TASK_7_OUTPUT_PATH_INVALID');
    }
    if (is_file($outputPath) && !unlink($outputPath)) {
        throw new TaskSevenCliFailure('TASK_7_OUTPUT_PATH_INVALID');
    }
    $arguments['output'] = $outputPath;

    return $arguments;
}

function verifyTaskSeven(array $arguments): array
{
    $baselineCommit = $arguments['baseline-commit'];
    $reviewedCommit = $arguments['reviewed-commit'];
    runGit(['rev-parse', '--verify', $baselineCommit.'^{commit}']);
    runGit(['rev-parse', '--verify', $reviewedCommit.'^{commit}']);
    $ancestor = new Process(['git', 'merge-base', '--is-ancestor', $baselineCommit, $reviewedCommit], getcwd());
    $ancestor->run();
    if ($ancestor->getExitCode() !== 0) {
        throw new TaskSevenGitFailure('TASK_7_BASE_NOT_ANCESTOR');
    }
    failContract($baselineCommit !== $reviewedCommit, 'TASK_7_BASE_NOT_STRICT_ANCESTOR');

    $baselineComposerBytes = gitDocument($baselineCommit, 'composer.json');
    $baselineLockBytes = gitDocument($baselineCommit, 'composer.lock');
    $reviewedComposerBytes = gitDocument($reviewedCommit, 'composer.json');
    $reviewedLockBytes = gitDocument($reviewedCommit, 'composer.lock');

    failContract(hash('sha256', $baselineComposerBytes) === $arguments['expected-composer-json-sha256'], 'TASK_7_BASELINE_COMPOSER_JSON_HASH_INVALID');
    failContract(hash('sha256', $baselineLockBytes) === $arguments['expected-composer-lock-sha256'], 'TASK_7_BASELINE_COMPOSER_LOCK_HASH_INVALID');

    $baselineComposer = decodeDocument($baselineComposerBytes);
    $reviewedComposer = decodeDocument($reviewedComposerBytes);
    failContract(($reviewedComposer['require']['opis/json-schema'] ?? null) === '^2.6', 'TASK_7_ROOT_CONSTRAINT_INVALID');
    $composerWithoutOpis = $reviewedComposer;
    unset($composerWithoutOpis['require']['opis/json-schema']);
    failContract(canonicalJson($composerWithoutOpis) === canonicalJson($baselineComposer), 'TASK_7_COMPOSER_CLOSED_TRANSFORM_DRIFT');

    $baselineLock = decodeDocument($baselineLockBytes);
    $reviewedLock = decodeDocument($reviewedLockBytes);
    $baselinePackages = packageMap($baselineLock['packages'] ?? null, 'TASK_7_PRODUCTION_PACKAGE_MAP_INVALID');
    $reviewedPackages = packageMap($reviewedLock['packages'] ?? null, 'TASK_7_PRODUCTION_PACKAGE_MAP_INVALID');
    $added = array_values(array_diff(array_keys($reviewedPackages), array_keys($baselinePackages)));
    sort($added, SORT_STRING);
    failContract($added === ['opis/json-schema', 'opis/string', 'opis/uri'], 'TASK_7_ADDED_PACKAGES_INVALID');
    failContract(array_diff(array_keys($baselinePackages), array_keys($reviewedPackages)) === [], 'TASK_7_PRODUCTION_PACKAGE_REMOVED');
    failContract(($reviewedPackages['opis/json-schema']['version'] ?? null) === '2.6.0', 'TASK_7_OPIS_VERSION_INVALID');
    foreach ($baselinePackages as $name => $package) {
        failContract(canonicalJson($package) === canonicalJson($reviewedPackages[$name]), 'TASK_7_PRODUCTION_PACKAGE_DRIFT');
    }

    $baselineDev = packageMap($baselineLock['packages-dev'] ?? null, 'TASK_7_DEV_PACKAGE_MAP_INVALID');
    $reviewedDev = packageMap($reviewedLock['packages-dev'] ?? null, 'TASK_7_DEV_PACKAGE_MAP_INVALID');
    failContract(canonicalJson($baselineDev) === canonicalJson($reviewedDev), 'TASK_7_DEV_PACKAGE_DRIFT');

    $baselineTransform = $baselineLock;
    $reviewedTransform = $reviewedLock;
    unset($baselineTransform['content-hash'], $reviewedTransform['content-hash']);
    $baselineTransform['packages'] = $baselinePackages;
    foreach ($added as $name) {
        unset($reviewedPackages[$name]);
    }
    $reviewedTransform['packages'] = $reviewedPackages;
    failContract(canonicalJson($baselineTransform) === canonicalJson($reviewedTransform), 'TASK_7_LOCK_CLOSED_TRANSFORM_DRIFT');

    $contentHash = $reviewedLock['content-hash'] ?? null;
    failContract(is_string($contentHash) && preg_match('/^[a-f0-9]{32}$/D', $contentHash) === 1, 'TASK_7_CONTENT_HASH_INVALID');
    failContract($contentHash !== ($baselineLock['content-hash'] ?? null), 'TASK_7_CONTENT_HASH_INVALID');
    failContract($contentHash === composerContentHash($reviewedComposer), 'TASK_7_CONTENT_HASH_INVALID');

    $changedPaths = preg_split('/\R/', trim(runGit(['diff', '--name-only', $baselineCommit.'..'.$reviewedCommit]))) ?: [];
    $dependencyPaths = array_values(array_filter($changedPaths, 'dependencyPath'));
    sort($dependencyPaths, SORT_STRING);
    failContract($dependencyPaths === ['composer.json', 'composer.lock'], 'TASK_7_DEPENDENCY_PATH_DRIFT');
    $stagedPaths = preg_split('/\R/', trim(runGit(['diff', '--cached', '--name-only']))) ?: [];
    failContract(array_values(array_filter($stagedPaths, 'dependencyPath')) === [], 'TASK_7_STAGED_DEPENDENCY_PATH');

    return [
        'status' => 'task_7_composer_contract_passed',
        'baseline_commit_sha' => $baselineCommit,
        'reviewed_commit_sha' => $reviewedCommit,
        'composer_json_before_sha256' => hash('sha256', $baselineComposerBytes),
        'composer_lock_before_sha256' => hash('sha256', $baselineLockBytes),
        'composer_json_after_sha256' => hash('sha256', $reviewedComposerBytes),
        'composer_lock_after_sha256' => hash('sha256', $reviewedLockBytes),
        'root_constraint' => '^2.6',
        'locked_opis_version' => '2.6.0',
        'added_packages' => $added,
        'content_hash' => $contentHash,
    ];
}

try {
    $arguments = parseArguments(array_slice($argv, 1));
    $arguments = resolveOutputPath($arguments);
    $evidence = verifyTaskSeven($arguments);
    if (isset($arguments['output'])) {
        $encoded = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $temporary = tempnam(dirname($arguments['output']), '.task-7-');
        if ($temporary === false || file_put_contents($temporary, $encoded, LOCK_EX) === false || !rename($temporary, $arguments['output'])) {
            if (is_string($temporary) && is_file($temporary)) {
                @unlink($temporary);
            }
            throw new TaskSevenCliFailure('TASK_7_OUTPUT_WRITE_FAILED');
        }
    }
    exit(0);
} catch (TaskSevenCliFailure $failure) {
    fwrite(STDERR, $failure->getMessage().PHP_EOL);
    exit(2);
} catch (TaskSevenGitFailure|JsonException $failure) {
    fwrite(STDERR, $failure->getMessage().PHP_EOL);
    exit(3);
} catch (TaskSevenContractFailure $failure) {
    fwrite(STDERR, $failure->getMessage().PHP_EOL);
    exit(4);
} catch (Throwable $failure) {
    fwrite(STDERR, 'TASK_7_GIT_READ_JSON_FAILURE'.PHP_EOL);
    exit(3);
}
