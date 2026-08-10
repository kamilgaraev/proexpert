<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evaluation;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class EvaluationReviewDecision
{
    public function __construct(
        public EvaluationExampleTrust $trustStatus,
        public string $actorType,
        public int $actorId,
        public string $reason,
        public DateTimeImmutable $decidedAt,
    ) {
        if ($trustStatus === EvaluationExampleTrust::Candidate) {
            throw new InvalidArgumentException('Evaluation review decision must be terminal.');
        }
        if (preg_match('/\A[a-z][a-z0-9_]{1,63}\z/', $actorType) !== 1 || $actorId < 1) {
            throw new InvalidArgumentException('Evaluation review actor is invalid.');
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Evaluation review reason is required.');
        }
    }
}
