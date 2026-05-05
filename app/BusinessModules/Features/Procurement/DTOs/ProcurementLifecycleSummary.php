<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\DTOs;

final readonly class ProcurementLifecycleSummary
{
    /**
     * @param array<int, string> $blockers
     * @param array<int, string> $warnings
     */
    public function __construct(
        public string $stage,
        public string $stageLabel,
        public ?string $nextAction,
        public ?string $nextActionLabel,
        public bool $canCreateSupplierRequest,
        public bool $canSendSupplierRequest,
        public bool $canSubmitProposal,
        public bool $canSelectProposal,
        public bool $canAcceptProposal,
        public bool $canCreateOrder,
        public bool $canReceiveMaterials,
        public array $blockers = [],
        public array $warnings = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'stage' => $this->stage,
            'stage_label' => $this->stageLabel,
            'next_action' => $this->nextAction,
            'next_action_label' => $this->nextActionLabel,
            'can_create_supplier_request' => $this->canCreateSupplierRequest,
            'can_send_supplier_request' => $this->canSendSupplierRequest,
            'can_submit_proposal' => $this->canSubmitProposal,
            'can_select_proposal' => $this->canSelectProposal,
            'can_accept_proposal' => $this->canAcceptProposal,
            'can_create_order' => $this->canCreateOrder,
            'can_receive_materials' => $this->canReceiveMaterials,
            'blockers' => $this->blockers,
            'warnings' => $this->warnings,
        ];
    }
}
