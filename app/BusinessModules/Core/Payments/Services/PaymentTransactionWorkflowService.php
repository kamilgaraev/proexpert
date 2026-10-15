<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

use function trans_message;

final class PaymentTransactionWorkflowService
{
    public function __construct(
        private readonly PaymentTransactionAccessService $access,
        private readonly PaymentTransactionService $transactions,
    ) {}

    public function changeStatus(int $organizationId, User $actor, int $id, string $action, array $data): PaymentTransaction
    {
        $permission = match ($action) {
            'approve' => 'payments.transaction.approve',
            'reject' => 'payments.transaction.reject',
            'cancel' => 'payments.transaction.edit',
        };

        return DB::transaction(function () use ($organizationId, $actor, $id, $action, $data, $permission): PaymentTransaction {
            $snapshot = $this->access->find($organizationId, $actor, $id, $permission);
            $document = $snapshot->payment_document_id === null ? null : PaymentDocument::query()->useWritePdo()
                ->where('organization_id', $organizationId)->whereKey($snapshot->payment_document_id)
                ->lockForUpdate()->firstOrFail();
            $transaction = PaymentTransaction::query()->useWritePdo()->where('organization_id', $organizationId)
                ->where('payment_document_id', $snapshot->payment_document_id)->whereKey($id)
                ->lockForUpdate()->firstOrFail();
            $this->access->assertTransaction($transaction, $actor, $permission, $document);
            $allowedStatuses = $action === 'cancel'
                ? [PaymentTransactionStatus::PENDING, PaymentTransactionStatus::PROCESSING]
                : [PaymentTransactionStatus::PENDING];
            $error = trans_message($action === 'cancel' ? 'payments.transactions.cancel_forbidden' : 'payments.transactions.pending_only');
            if (! in_array($transaction->status, $allowedStatuses, true)) {
                throw new \DomainException($error);
            }
            $notes = match ($action) {
                'approve' => ! empty($data['notes'])
                    ? $this->appendNote($transaction->notes, $data['notes']) : trim((string) $transaction->notes),
                'reject' => $this->appendNote($transaction->notes, trans_message('payments.transactions.reject_note', ['reason' => $data['reason']])),
                'cancel' => $this->appendNote($transaction->notes, ! empty($data['reason'])
                    ? trans_message('payments.transactions.cancel_note', ['reason' => $data['reason']])
                    : trans_message('payments.transactions.cancel_note_without_reason')),
            };
            $changes = [
                'status' => match ($action) {
                    'approve' => PaymentTransactionStatus::COMPLETED->value,
                    'reject' => PaymentTransactionStatus::FAILED->value,
                    'cancel' => PaymentTransactionStatus::CANCELLED->value,
                },
                'notes' => $notes !== '' ? $notes : null,
            ];
            if ($action === 'approve') {
                $changes['approved_by_user_id'] = $actor->id;
            }
            $updated = PaymentTransaction::query()->where('organization_id', $organizationId)->whereKey($id)
                ->whereIn('status', array_map(static fn (PaymentTransactionStatus $status): string => $status->value, $allowedStatuses))
                ->update($changes);
            if ($updated !== 1) {
                throw new \DomainException($error);
            }

            return $this->access->findForResponse($organizationId, $actor, $id, $permission);
        }, 3);
    }

    public function refund(int $organizationId, User $actor, int $id, array $data): array
    {
        $permission = 'payments.transaction.refund';

        return DB::transaction(function () use ($organizationId, $actor, $id, $data, $permission): array {
            $this->access->find($organizationId, $actor, $id, $permission);
            $payload = $this->transactions->refundPayment(
                $id, $organizationId, (int) $actor->id, $data['amount'] ?? null, $data['reason'],
                $data['refund_date'] ?? null, $data['idempotency_key'],
                fn (PaymentTransaction $transaction, PaymentDocument $document) => $this->access->assertTransaction(
                    $transaction, $actor, $permission, $document,
                ),
            );
            foreach (['original_transaction', 'refund_transaction'] as $key) {
                $payload[$key] = $this->access->findForResponse($organizationId, $actor, (int) $payload[$key]->id, $permission);
            }

            return $payload;
        }, 3);
    }

    private function appendNote(?string $currentNote, string $newNote): string
    {
        return trim(trim((string) $currentNote).PHP_EOL.$newNote);
    }
}
