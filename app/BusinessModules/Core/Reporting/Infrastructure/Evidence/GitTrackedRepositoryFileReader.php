<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Evidence;

use App\BusinessModules\Core\Reporting\Domain\Contracts\TrackedRepositoryFileReader;
use App\BusinessModules\Core\Reporting\Domain\DTO\TrackedPlanDocument;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

final class GitTrackedRepositoryFileReader implements TrackedRepositoryFileReader
{
    private const PATHS = [
        'docs/superpowers/plans/2026-07-26-reports-plan-1b-execution-exports.md',
        'docs/superpowers/plans/2026-07-26-reports-plan-1c-catalog-workspace-quality.md',
    ];

    public function read(string $repositoryRoot, string $relativePath, string $commitSha): TrackedPlanDocument
    {
        if (!in_array($relativePath, self::PATHS, true) || preg_match('/^[a-f0-9]{40}$/D', $commitSha) !== 1) throw new InvalidArgumentException('tracked_plan_document_invalid');
        $root = realpath($repositoryRoot);
        $path = $root === false ? false : realpath($root.'/'.$relativePath);
        if ($path === false || is_link($path)) throw new InvalidArgumentException('tracked_plan_document_missing');
        $tracked = new Process(['git', 'ls-files', '--error-unmatch', '--', $relativePath], $root); $tracked->run();
        if (!$tracked->isSuccessful()) throw new InvalidArgumentException('tracked_plan_document_untracked');
        $working = file_get_contents($path);
        $blob = new Process(['git', 'show', $commitSha.':'.$relativePath], $root); $blob->run();
        if (!is_string($working) || !$blob->isSuccessful() || !hash_equals($working, $blob->getOutput())) throw new InvalidArgumentException('tracked_plan_document_dirty');
        return new TrackedPlanDocument($relativePath, $commitSha, new Sha256Hash(hash('sha256', $working)), $working);
    }
}
