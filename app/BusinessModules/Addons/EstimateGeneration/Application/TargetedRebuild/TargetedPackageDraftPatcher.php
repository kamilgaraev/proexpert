<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use InvalidArgumentException;
use LogicException;

final class TargetedPackageDraftPatcher
{
    public function replace(
        array $draft,
        string $expectedSourceInputVersion,
        string $packageKey,
        array $replacement,
    ): TargetedPackagePatchResult {
        $this->assertSourceInputVersion($draft, $expectedSourceInputVersion);
        $this->assertPackageKey($packageKey);
        $this->assertReplacementKey($replacement, $packageKey);

        $localEstimates = $this->localEstimates($draft);
        [$targetIndex, $targetBefore, $nonTargetFingerprints] = $this->targetAndFingerprints($localEstimates, $packageKey);

        $draft['local_estimates'][$targetIndex] = $replacement;
        $this->assertNonTargetsUnchanged($draft['local_estimates'], $packageKey, $nonTargetFingerprints);

        return new TargetedPackagePatchResult(
            $draft,
            $packageKey,
            $this->fingerprint($targetBefore),
            $this->fingerprint($replacement),
            $nonTargetFingerprints,
        );
    }

    private function assertSourceInputVersion(array $draft, string $expectedSourceInputVersion): void
    {
        if (! $this->isSourceInputVersion($expectedSourceInputVersion)) {
            throw new InvalidArgumentException('Expected source input version is invalid.');
        }

        $actualSourceInputVersion = $draft['source_input_version'] ?? null;

        if (! is_string($actualSourceInputVersion) || ! $this->isSourceInputVersion($actualSourceInputVersion)) {
            throw new InvalidArgumentException('Draft source input version is invalid.');
        }

        if (! hash_equals($actualSourceInputVersion, $expectedSourceInputVersion)) {
            throw new InvalidArgumentException('Draft source input version does not match the expected version.');
        }
    }

    private function assertPackageKey(string $packageKey): void
    {
        if (preg_match('/^[A-Za-z0-9:._-]{1,120}$/', $packageKey) !== 1) {
            throw new InvalidArgumentException('Package key is invalid.');
        }
    }

    private function assertReplacementKey(array $replacement, string $packageKey): void
    {
        $replacementKey = $replacement['key'] ?? null;

        if (! is_string($replacementKey)) {
            throw new InvalidArgumentException('Replacement package key is invalid.');
        }

        $this->assertPackageKey($replacementKey);

        if (! hash_equals($packageKey, $replacementKey)) {
            throw new InvalidArgumentException('Replacement package key does not match the target package key.');
        }
    }

    private function localEstimates(array $draft): array
    {
        $localEstimates = $draft['local_estimates'] ?? null;

        if (! is_array($localEstimates) || ! array_is_list($localEstimates) || $localEstimates === []) {
            throw new InvalidArgumentException('Draft local estimates must be a non-empty list.');
        }

        foreach ($localEstimates as $localEstimate) {
            if (! is_array($localEstimate)) {
                throw new InvalidArgumentException('Each local estimate must be an array.');
            }
        }

        return $localEstimates;
    }

    private function targetAndFingerprints(array $localEstimates, string $targetPackageKey): array
    {
        $targetIndex = null;
        $target = null;
        $nonTargetFingerprints = [];
        $seenPackageKeys = [];

        foreach ($localEstimates as $index => $localEstimate) {
            $packageKey = $localEstimate['key'] ?? null;

            if (! is_string($packageKey)) {
                throw new InvalidArgumentException('Draft package key is invalid.');
            }

            $this->assertPackageKey($packageKey);

            if (isset($seenPackageKeys[$packageKey])) {
                throw new InvalidArgumentException('Draft package keys must be unique.');
            }

            $seenPackageKeys[$packageKey] = true;

            if (hash_equals($targetPackageKey, $packageKey)) {
                $targetIndex = $index;
                $target = $localEstimate;

                continue;
            }

            $nonTargetFingerprints[$packageKey] = $this->fingerprint($localEstimate);
        }

        if ($targetIndex === null || $target === null) {
            throw new InvalidArgumentException('Target package does not exist in the draft.');
        }

        ksort($nonTargetFingerprints, SORT_STRING);

        return [$targetIndex, $target, $nonTargetFingerprints];
    }

    private function assertNonTargetsUnchanged(array $localEstimates, string $targetPackageKey, array $expectedFingerprints): void
    {
        $actualFingerprints = [];

        foreach ($localEstimates as $localEstimate) {
            $packageKey = $localEstimate['key'];

            if (hash_equals($targetPackageKey, $packageKey)) {
                continue;
            }

            $actualFingerprints[$packageKey] = $this->fingerprint($localEstimate);
        }

        ksort($actualFingerprints, SORT_STRING);

        if (array_keys($actualFingerprints) !== array_keys($expectedFingerprints)) {
            throw new LogicException('Non-target packages changed during replacement.');
        }

        foreach ($expectedFingerprints as $packageKey => $expectedFingerprint) {
            $actualFingerprint = $actualFingerprints[$packageKey] ?? null;

            if (! is_string($actualFingerprint) || ! hash_equals($expectedFingerprint, $actualFingerprint)) {
                throw new LogicException('Non-target packages changed during replacement.');
            }
        }
    }

    private function isSourceInputVersion(string $sourceInputVersion): bool
    {
        return preg_match('/^sha256:[a-f0-9]{64}$/', $sourceInputVersion) === 1;
    }

    private function fingerprint(array $package): string
    {
        return 'sha256:'.hash('sha256', CanonicalPipelineJson::encode($package));
    }
}
