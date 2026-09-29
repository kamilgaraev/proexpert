<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence;

use App\Models\Organization;
use App\Models\User;

final class GetEstimatePositionsTool extends ReadonlyEstimateTool
{
    public function __construct(private readonly AssistantEstimateEvidenceService $evidence) {}

    public function getName(): string { return 'get_estimate_positions'; }

    public function getDescription(): string { return trans_message('ai_assistant_financial.positions_description'); }

    public function getParametersSchema(): array
    {
        return $this->schema(['estimate_id' => ['type' => 'integer', 'minimum' => 1], 'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000000],
            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]]);
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        $actor = $this->actor($user);
        $arguments = $this->validate($arguments, ['estimate_id' => ['required', 'integer', 'min:1'],
            'page' => ['required', 'integer', 'between:1,1000000'], 'per_page' => ['required', 'integer', 'between:1,100']]);
        $evidence = $this->evidence->snapshot((int) $arguments['estimate_id'], (int) $organization->id, $actor);
        $positions = array_slice($evidence['positions'], ((int) $arguments['page'] - 1) * (int) $arguments['per_page'], (int) $arguments['per_page']);

        return ['estimate' => $evidence['estimate'], 'positions' => $positions,
            'meta' => ['page' => (int) $arguments['page'], 'per_page' => (int) $arguments['per_page'], 'total' => $evidence['position_count']],
            'source_refs' => AssistantEstimateEvidenceService::publicSourceReferences($evidence, $positions),
            'fetched_at' => $evidence['fetched_at'], 'validation_status' => $evidence['validation_status']];
    }
}
