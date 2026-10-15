<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class PaymentTransactionAccessService
{
    public function __construct(private readonly PaymentDocumentAccessService $documents) {}

    public function query(int $organizationId, User $actor, string $permission): Builder
    {
        return $this->documents->scope(PaymentTransaction::query(), $organizationId, $actor, $permission)
            ->where(function (Builder $query) use ($organizationId, $actor, $permission): void {
                $query->whereNull('payment_transactions.payment_document_id')
                    ->orWhereHas('paymentDocument', fn (Builder $documents) => $this->documents->scope(
                        $documents, $organizationId, $actor, $permission,
                    ));
            });
    }

    public function find(int $organizationId, User $actor, int|string $id, string $permission): PaymentTransaction
    {
        return $this->query($organizationId, $actor, $permission)->findOrFail($this->validatedId($id));
    }

    public function responseQuery(int $organizationId, User $actor, string $permission): Builder
    {
        return $this->query($organizationId, $actor, $permission)
            ->withRefundAvailability($organizationId)
            ->with([
                'paymentDocument' => fn ($relation) => $this->documents->scope($relation->getQuery(), $organizationId, $actor, $permission),
                'createdBy', 'approvedBy',
            ]);
    }

    public function findForResponse(int $organizationId, User $actor, int|string $id, string $permission): PaymentTransaction
    {
        $transaction = $this->responseQuery($organizationId, $actor, $permission)->findOrFail($this->validatedId($id));
        if ($transaction->payment_document_id !== null && $transaction->paymentDocument === null) {
            throw (new ModelNotFoundException)->setModel(PaymentTransaction::class);
        }

        return $transaction;
    }

    public function assertFilters(int $organizationId, User $actor, array $filters): void
    {
        foreach (['payment_document_id', 'invoice_id'] as $field) {
            $id = filter_var($filters[$field] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false) {
                $this->documents->find($organizationId, $actor, $id, 'payments.transaction.view');
            }
        }
        $projectId = filter_var($filters['project_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($projectId !== false) {
            $this->documents->assertProject($organizationId, $actor, $projectId, 'payments.transaction.view');
        }
    }

    public function assertTransaction(PaymentTransaction $transaction, User $actor, string $permission, ?PaymentDocument $document = null): void
    {
        $organizationId = (int) $transaction->organization_id;
        $this->documents->assertProject($organizationId, $actor,
            $transaction->project_id === null ? null : (int) $transaction->project_id, $permission);
        if ($transaction->payment_document_id === null) {
            return;
        }
        $document ??= PaymentDocument::query()->useWritePdo()->where('organization_id', $organizationId)
            ->findOrFail($transaction->payment_document_id);
        if ((int) $document->organization_id !== $organizationId
            || (int) $document->id !== (int) $transaction->payment_document_id) {
            throw (new ModelNotFoundException)->setModel(PaymentTransaction::class);
        }
        $this->documents->assertDocument($document, $actor, $permission);
    }

    private function validatedId(int|string $id): int
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw (new ModelNotFoundException)->setModel(PaymentTransaction::class);
        }

        return $id;
    }
}
