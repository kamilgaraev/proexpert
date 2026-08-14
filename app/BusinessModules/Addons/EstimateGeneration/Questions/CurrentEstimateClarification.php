<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Questions;

use InvalidArgumentException;

final readonly class CurrentEstimateClarification
{
    public function __construct(
        public EstimateClarificationQuestion $question,
        public string $sourceVersion,
        public string $snapshotToken,
        public string $answerFingerprint,
        public string $targetFactId,
    ) {
        if (preg_match('/^sha256:[a-f0-9]{64}$/D', $sourceVersion) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $snapshotToken) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $answerFingerprint) !== 1
            || ! str_starts_with($targetFactId, 'fact:')) {
            throw new InvalidArgumentException('estimate_clarification_snapshot_invalid');
        }
    }
}
