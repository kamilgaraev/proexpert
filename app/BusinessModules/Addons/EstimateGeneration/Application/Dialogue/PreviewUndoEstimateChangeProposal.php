<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue;

use App\BusinessModules\Addons\EstimateGeneration\Infrastructure\Dialogue\EstimateChangeProposalRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final readonly class PreviewUndoEstimateChangeProposal
{
    public function __construct(
        private EstimateChangeProposalRepository $proposals,
        private EstimateCommandContextBuilder $contexts,
        private CanonicalEstimateCommandProposalResolver $resolver,
        private EstimateUndoInterpretationFactory $factory,
        private PreviewEstimateChange $preview,
    ) {}

    public function handle(
        EstimateGenerationSession $session,
        int $actorId,
        string $proposalId,
        string $idempotencyKey,
    ): EstimateChangeProposal {
        $original = $this->proposals->find(
            $proposalId,
            (int) $session->organization_id,
            (int) $session->project_id,
            (int) $session->getKey(),
        );
        $context = $this->contexts->build($session);
        $interpretation = $this->resolver->resolve($this->factory->make($original, $context), $context);

        return $this->preview->handle(
            $session,
            $actorId,
            'Отменить изменение: '.mb_substr((string) ($original->payload['command_excerpt'] ?? ''), 0, 900),
            $idempotencyKey,
            $interpretation,
        );
    }
}
