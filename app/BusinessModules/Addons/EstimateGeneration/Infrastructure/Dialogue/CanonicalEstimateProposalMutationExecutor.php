<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Infrastructure\Dialogue;

use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateChangeProposal;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateProposalMutationExecutor;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ApplyProjectModelCorrection;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\ActorContext;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendationDecisionService;
use App\Models\User;
use RuntimeException;

final readonly class CanonicalEstimateProposalMutationExecutor implements EstimateProposalMutationExecutor
{
    public function __construct(private ApplyProjectModelCorrection $corrections, private TechnologyRecommendationDecisionService $technologies) {}

    public function apply(User $actor, EstimateGenerationSession $session, EstimateChangeProposal $proposal): array
    {
        $after = is_array($proposal->payload['after_payload'] ?? null) ? $proposal->payload['after_payload'] : [];
        $key = 'estimate-proposal:'.$proposal->id();
        if ($proposal->payload['intent'] === 'correct_fact') {
            foreach (['source_version', 'value_fingerprint', 'assertion_stable_key', 'value'] as $field) {
                if (! array_key_exists($field, $after)) {
                    throw new RuntimeException('estimate_generation.proposal_payload_invalid');
                }
            }

            return $this->corrections->apply(
                (int) $session->organization_id, (int) $session->project_id, (int) $session->id, (int) $actor->id,
                (string) $after['source_version'], (string) $after['value_fingerprint'], (string) $after['assertion_stable_key'],
                is_array($after['value']) ? $after['value'] : [], (string) ($after['reason'] ?? trans_message('estimate_generation.confirmed_correction_reason')),
                $key, (int) ($after['decision_version'] ?? 0),
            );
        }
        if ($proposal->payload['intent'] === 'select_technology') {
            $context = new ActorContext((int) $session->organization_id, (int) $session->project_id, (int) $actor->id, $key, (string) ($after['source_version'] ?? ''));
            $decision = $this->technologies->respond(
                $actor, $session, $context, (int) ($after['planning_run_id'] ?? 0), (string) ($after['decision_key'] ?? ''),
                (string) ($after['response'] ?? ''), isset($after['other']) ? (string) $after['other'] : null,
                (string) ($after['reason'] ?? trans_message('estimate_generation.confirmed_technology_reason')),
            );

            return ['decision_id' => $decision?->id, 'reanalysis_requested' => true];
        }
        throw new RuntimeException('estimate_generation.proposal_intent_unsupported');
    }
}
