<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Models\ProcurementApproval;
use App\BusinessModules\Features\Procurement\Models\ProcurementApprovalPolicy;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\Models\User;
use Illuminate\Validation\ValidationException;

use function trans_message;

class ProcurementDutySeparationService
{
    public function ensureCanResolve(
        ProcurementApproval $approval,
        SupplierProposalDecision $decision,
        ProcurementApprovalPolicy $policy,
        int $actorId
    ): void {
        $blockers = $this->resolutionBlockers($approval, $decision, $policy, $actorId);

        if ($blockers === []) {
            return;
        }

        $this->throwViolation($blockers[0]['code']);
    }

    public function resolutionBlockers(
        ProcurementApproval $approval,
        SupplierProposalDecision $decision,
        ProcurementApprovalPolicy $policy,
        int $actorId
    ): array {
        if (! $policy->is_active) {
            return [];
        }

        if ($this->isOrganizationOwner($approval, $actorId)) {
            return [];
        }

        $blockers = [];

        if ($policy->prevent_requester_approval && $approval->requested_by !== null && (int) $approval->requested_by === $actorId) {
            $blockers[] = $this->blocker('requester');
        }

        if ($policy->prevent_selector_approval && $decision->selected_by !== null && (int) $decision->selected_by === $actorId) {
            $blockers[] = $this->blocker('selector');
        }

        $decision->loadMissing('winningProposal.intake');
        $enteredBy = $decision->winningProposal?->intake?->entered_by;

        if ($policy->prevent_intake_author_approval && $enteredBy !== null && (int) $enteredBy === $actorId) {
            $blockers[] = $this->blocker('intake_author');
        }

        return $blockers;
    }

    private function blocker(string $rule): array
    {
        return [
            'code' => $rule,
            'message' => trans_message("procurement_enterprise.duty_separation.{$rule}"),
        ];
    }

    private function throwViolation(string $rule): void
    {
        throw ValidationException::withMessages([
            'approval' => [trans_message("procurement_enterprise.duty_separation.{$rule}")],
        ]);
    }

    private function isOrganizationOwner(ProcurementApproval $approval, int $actorId): bool
    {
        $user = User::query()->find($actorId);

        return $user instanceof User
            && $user->isOrganizationOwner((int) $approval->organization_id);
    }
}
