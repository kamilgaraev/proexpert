<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Cycle;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProcurementCycleOwnerWorkflowContractTest extends TestCase
{
    #[DataProvider('ownerBoundaryProvider')]
    public function test_owner_records_transition_after_mutation_and_before_commit(
        string $service,
        string $mutation,
        string $recordingCall,
    ): void {
        $source = $this->service($service);
        $recordingPosition = strpos($source, $recordingCall);
        $prefix = substr($source, 0, (int) $recordingPosition);
        $mutationPosition = strrpos($prefix, $mutation);
        $transactionPosition = max(
            (int) strrpos($prefix, 'DB::beginTransaction()'),
            (int) strrpos($prefix, 'DB::transaction('),
        );

        self::assertNotFalse($mutationPosition, $service.' must persist its owner mutation.');
        self::assertNotFalse($recordingPosition, $service.' must invoke the R15 sink.');
        self::assertLessThan($recordingPosition, $mutationPosition);
        self::assertGreaterThan(0, $transactionPosition, $service.' must open a transaction before the R15 sink.');
        self::assertLessThan($recordingPosition, $transactionPosition);
    }

    public static function ownerBoundaryProvider(): array
    {
        return [
            'request create' => ['PurchaseRequestService.php', '$purchaseRequest->save()', 'recordRequestCreated('],
            'request terminal' => ['PurchaseRequestService.php', '$request->save()', 'recordRequestApproved('],
            'supplier request send' => ['SupplierRequestService.php', '$supplierRequest->update([', 'recordSolicitationSent('],
            'supplier response' => ['SupplierProposalService.php', '$proposal->update([', 'recordSupplierResponded('],
            'award decision' => ['SupplierProposalService.php', '$lockedProposal->update([', 'recordAwardDecided('],
            'order send' => ['PurchaseOrderService.php', '$order->update([', 'recordOrderSent('],
            'receipt milestones' => ['PurchaseOrderService.php', '$order->receipts()->create([', 'recordReceiptMilestones('],
        ];
    }

    public function test_request_terminal_signatures_preserve_typed_reason_semantics(): void
    {
        $source = $this->service('PurchaseRequestService.php');
        $approve = $this->between($source, 'public function approve(', 'public function reject(');
        $reject = $this->between($source, 'public function reject(', 'public function assignToSupplier(');

        self::assertStringNotContainsString('ProcurementTerminalReason::', $approve);
        self::assertStringContainsString(
            'ProcurementTerminalReason::REQUEST_REJECTED',
            $reject,
        );
    }

    public function test_approval_handoff_calls_award_owner_only_after_final_blocker_clears(): void
    {
        $source = $this->service('ProcurementApprovalService.php');
        $approve = $this->between($source, 'public function approve(', 'public function reject(');
        $blockingCheck = strpos($approve, '$blockingApprovalsExist =');
        $finalGate = strpos($approve, 'if (! $blockingApprovalsExist)');
        $ownerHandoff = strpos($approve, '$this->acceptApprovedWinningProposal(');

        self::assertNotFalse($blockingCheck);
        self::assertNotFalse($finalGate);
        self::assertNotFalse($ownerHandoff);
        self::assertLessThan($finalGate, $blockingCheck);
        self::assertLessThan($ownerHandoff, $finalGate);
    }

    private function service(string $file): string
    {
        $contents = file_get_contents(
            dirname(__DIR__, 5).'/app/BusinessModules/Features/Procurement/Services/'.$file,
        );
        self::assertIsString($contents);

        return $contents;
    }

    private function between(string $source, string $start, string $end): string
    {
        $startPosition = strpos($source, $start);
        $endPosition = strpos($source, $end, (int) $startPosition);
        self::assertNotFalse($startPosition);
        self::assertNotFalse($endPosition);

        return substr($source, (int) $startPosition, (int) $endPosition - (int) $startPosition);
    }
}
