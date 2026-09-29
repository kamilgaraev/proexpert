<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence;

use App\Models\Organization;
use App\Models\User;

final class GetEstimateFinancialSnapshotTool extends ReadonlyEstimateTool
{
    public function __construct(private readonly AssistantEstimateEvidenceService $evidence, private readonly AssistantFinancialAnswerService $answers) {}

    public function getName(): string { return 'get_estimate_financial_snapshot'; }

    public function getDescription(): string { return trans_message('ai_assistant_financial.snapshot_description'); }

    public function getParametersSchema(): array
    {
        return $this->schema(['estimate_id' => ['type' => 'integer', 'minimum' => 1]]);
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        $actor = $this->actor($user);
        $arguments = $this->validate($arguments, ['estimate_id' => ['required', 'integer', 'min:1']]);
        $evidence = $this->evidence->snapshot((int) $arguments['estimate_id'], (int) $organization->id, $actor);

        $receipt = array_diff_key($evidence, ['positions' => true]);

        return ['financial_evidence' => $receipt, 'server_formatted_answer' => $this->answers->format('', $evidence),
            'source_refs' => $evidence['source_refs'], 'validation_status' => $evidence['validation_status']];
    }
}
