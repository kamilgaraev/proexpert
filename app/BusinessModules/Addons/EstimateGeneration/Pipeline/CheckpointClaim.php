<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use Illuminate\Support\Str;
use LogicException;

final readonly class CheckpointClaim
{
    public CheckpointClaimStatus $status;

    public PipelineContext $context;

    public ProcessingStage $stage;

    public ?string $claimToken;

    public int $attempt;

    public ?int $checkpointId;

    private function __construct(
        CheckpointClaimStatus $status,
        PipelineContext $context,
        ProcessingStage $stage,
        ?string $claimToken,
        int $attempt = 1,
        ?int $checkpointId = null,
    ) {
        $claimToken = $claimToken === null ? null : strtolower($claimToken);

        if (($status === CheckpointClaimStatus::Acquired) !== ($claimToken !== null && $claimToken !== '')) {
            throw new LogicException('Only an acquired checkpoint claim may have an ownership token.');
        }
        if ($attempt < 1 || ($checkpointId !== null && $checkpointId < 1)) {
            throw new LogicException('Checkpoint claim attempt and identity must be positive.');
        }

        if (
            $claimToken !== null
            && (
                ! Str::isUuid($claimToken)
                || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $claimToken) !== 1
            )
        ) {
            throw new LogicException('Checkpoint ownership token must be a canonical UUID.');
        }

        $this->status = $status;
        $this->context = $context;
        $this->stage = $stage;
        $this->claimToken = $claimToken;
        $this->attempt = $attempt;
        $this->checkpointId = $checkpointId;
    }

    public static function acquired(
        PipelineContext $context,
        ProcessingStage $stage,
        string $claimToken,
        int $attempt = 1,
        ?int $checkpointId = null,
    ): self {
        return new self(CheckpointClaimStatus::Acquired, $context, $stage, $claimToken, $attempt, $checkpointId);
    }

    public static function alreadyCompleted(PipelineContext $context, ProcessingStage $stage): self
    {
        return new self(CheckpointClaimStatus::AlreadyCompleted, $context, $stage, null);
    }

    public static function busy(PipelineContext $context, ProcessingStage $stage): self
    {
        return new self(CheckpointClaimStatus::Busy, $context, $stage, null);
    }
}
