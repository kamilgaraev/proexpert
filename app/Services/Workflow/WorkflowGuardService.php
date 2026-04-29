<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use App\BusinessModules\Features\BudgetEstimates\Services\JournalContractCoverageService;
use App\Models\ConstructionJournalEntry;
use App\Models\User;
use App\Services\Logging\LoggingService;
use DomainException;

use function trans_message;

class WorkflowGuardService
{
    public const PERMISSION_OVERRIDE = 'workflow.override';

    public function __construct(
        private readonly JournalContractCoverageService $coverageService,
        private readonly LoggingService $loggingService,
    ) {
    }

    public function journalEntryBlockers(ConstructionJournalEntry $entry): array
    {
        $entry->loadMissing([
            'journal.contract.contractor',
            'workVolumes.estimateItem.contractLinks.contract.contractor',
        ]);

        $blockers = [];

        foreach ($entry->workVolumes as $volume) {
            if (!$volume->estimate_item_id) {
                $blockers[] = $this->blocker(
                    'missing_estimate_item',
                    trans_message('workflow.blockers.missing_estimate_item'),
                    'estimate_item_missing',
                    false,
                    ['select_estimate_item'],
                    $volume->id,
                );
                continue;
            }

            $coverage = $this->coverageService->resolve($entry->journal, $volume->estimateItem);
            $status = $coverage['contract_coverage_status'] ?? null;

            if ($status === JournalContractCoverageService::STATUS_COVERED) {
                continue;
            }

            if ($status === JournalContractCoverageService::STATUS_AMBIGUOUS) {
                $blockers[] = $this->blocker(
                    'contract_selection_required',
                    trans_message('workflow.blockers.contract_selection_required'),
                    'contract_missing',
                    true,
                    ['select_contract'],
                    $volume->id,
                );
                continue;
            }

            if ($status === JournalContractCoverageService::STATUS_AUTO_ATTACH_AVAILABLE) {
                $blockers[] = $this->blocker(
                    'contract_coverage_missing',
                    trans_message('workflow.blockers.contract_coverage_missing'),
                    'contract_missing',
                    true,
                    ['add_contract_coverage'],
                    $volume->id,
                );
                continue;
            }

            $blockers[] = $this->blocker(
                'contract_missing',
                trans_message('workflow.blockers.contract_missing'),
                'contract_missing',
                true,
                ['add_contract_coverage'],
                $volume->id,
            );
        }

        if (!$entry->schedule_task_id) {
            $blockers[] = $this->blocker(
                'schedule_missing',
                trans_message('workflow.blockers.schedule_missing'),
                'schedule_missing',
                true,
                ['link_schedule_task'],
                null,
            );
        }

        return array_values($blockers);
    }

    public function assertJournalEntryConfirmable(
        ConstructionJournalEntry $entry,
        ?User $user,
        ?array $override = null,
        string $operation = 'journal_approve',
    ): void {
        $blockers = $this->journalEntryBlockers($entry);

        if ($blockers === []) {
            return;
        }

        $notOverridable = array_values(array_filter(
            $blockers,
            fn (array $blocker): bool => !($blocker['can_override'] ?? false)
        ));

        if ($notOverridable !== []) {
            throw new DomainException($this->formatBlockersMessage($notOverridable));
        }

        if (!$this->overrideEnabled($override)) {
            throw new DomainException($this->formatBlockersMessage($blockers));
        }

        $target = (string) ($override['target'] ?? '');
        $reason = trim((string) ($override['reason'] ?? ''));

        if ($reason === '') {
            throw new DomainException(trans_message('workflow.override_reason_required'));
        }

        if (!$user || !$user->can(self::PERMISSION_OVERRIDE, ['organization_id' => (int) $entry->journal->organization_id])) {
            throw new DomainException(trans_message('workflow.override_forbidden'));
        }

        $unexpectedTargets = array_values(array_unique(array_filter(
            array_map(fn (array $blocker): string => (string) ($blocker['target'] ?? ''), $blockers),
            fn (string $blockerTarget): bool => $blockerTarget !== $target
        )));

        if ($unexpectedTargets !== []) {
            throw new DomainException($this->formatBlockersMessage($blockers));
        }

        $this->loggingService->audit('workflow.override.used', [
            'operation' => $operation,
            'target' => $target,
            'reason' => $reason,
            'user_id' => $user->id,
            'organization_id' => $entry->journal->organization_id,
            'project_id' => $entry->journal->project_id,
            'journal_id' => $entry->journal_id,
            'journal_entry_id' => $entry->id,
            'blockers' => $blockers,
        ]);
    }

    private function blocker(
        string $code,
        string $message,
        string $target,
        bool $canOverride,
        array $availableActions,
        ?int $journalWorkVolumeId,
    ): array {
        return [
            'code' => $code,
            'message' => $message,
            'target' => $target,
            'can_override' => $canOverride,
            'available_actions' => $availableActions,
            'journal_work_volume_id' => $journalWorkVolumeId,
        ];
    }

    private function overrideEnabled(?array $override): bool
    {
        return (bool) ($override['enabled'] ?? false);
    }

    private function formatBlockersMessage(array $blockers): string
    {
        $messages = array_values(array_unique(array_map(
            fn (array $blocker): string => (string) ($blocker['message'] ?? ''),
            $blockers
        )));

        return trans_message('workflow.blocked') . ': ' . implode('; ', array_filter($messages));
    }
}
