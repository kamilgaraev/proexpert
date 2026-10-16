<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Exceptions\PaymentDocumentDeviationBlockedException;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;

use App\Models\User;
use DateTime;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function trans_message;

final class PaymentDocumentWorkflowService
{
    public function __construct(
        private readonly PaymentDocumentService $documents,
        private readonly PaymentPurposeGenerator $purposeGenerator,
        private readonly PaymentDocumentAccessService $access,
        private readonly PaymentDocumentReferenceAccessService $references,
        private readonly ContractPaymentLockService $contractLocks,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{document: PaymentDocument, warnings: array<int, string>}
     */
    public function create(int $organizationId, int $userId, array $data, ?User $actor = null): array
    {
        $data['organization_id'] = $organizationId;
        $data['created_by_user_id'] = $userId;
        $actor = $this->access->actor($userId, $actor);

        return DB::transaction(function () use ($organizationId, $actor, $data): array {
            $this->references->assertInput($organizationId, $actor, 'payments.invoice.create', $data);
            $warnings = [];

            if (! empty($data['estimate_splits'])) {
                $deviationAnalysis = $this->documents->analyzePriceDeviation($data['estimate_splits']);

                if (($deviationAnalysis['is_blocked'] ?? false) && empty($data['overprice_justification'])) {
                    throw new PaymentDocumentDeviationBlockedException(
                        $deviationAnalysis,
                        trans_message('payments.documents.deviation_justification_required')
                    );
                }

                if ($deviationAnalysis['requires_approval'] ?? false) {
                    $warnings[] = trans_message('payments.documents.deviation_warning');
                }
            }

            $document = $this->documents->create($data);
            $this->access->assertDocument($document, $actor, 'payments.invoice.create');

            if (! empty($data['overprice_justification'])) {
                $document->notes = trim(
                    ($document->notes ?? '')
                    ."\n\n"
                    .trans_message('payments.documents.overprice_justification_note')
                    ."\n"
                    .$data['overprice_justification']
                );
                $document->saveQuietly();
            }

            $document->load('estimateSplits.estimateItem');

            return [
                'document' => $document,
                'warnings' => $warnings,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PaymentDocument $document, array $data, User $actor): PaymentDocument
    {
        return $this->mutate($document, $actor, 'payments.invoice.edit', function (PaymentDocument $current) use ($actor, $data): PaymentDocument {
            $this->references->assertInput((int) $current->organization_id, $actor, 'payments.invoice.edit', $data,
                $current->project_id === null ? null : (int) $current->project_id);

            return $this->documents->update($current, $data);
        });
    }

    public function submit(PaymentDocument $document, ?User $user, ?string $budgetOverrideReason): PaymentDocument
    {
        $actor = $this->access->actor((int) ($user?->id ?? 0), $user);

        return $this->mutate($document, $actor, 'payments.invoice.issue',
            fn (PaymentDocument $current): PaymentDocument => $this->documents->submit($current, $actor, $budgetOverrideReason));
    }

    public function schedule(
        PaymentDocument $document,
        ?string $scheduledAt,
        ?User $user,
        ?string $budgetOverrideReason
    ): PaymentDocument {
        $actor = $this->access->actor((int) ($user?->id ?? 0), $user);

        return $this->mutate($document, $actor, 'payments.schedule.create', fn (PaymentDocument $current): PaymentDocument => $this->documents->schedule(
            $current,
            $scheduledAt !== null ? new DateTime($scheduledAt) : null,
            $actor,
            $budgetOverrideReason
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function registerPayment(PaymentDocument $document, int $userId, array $data, ?User $actor = null): PaymentDocument
    {
        if (! isset($data['transaction_date']) && isset($data['payment_date'])) {
            $data['transaction_date'] = $data['payment_date'];
        }

        $data['created_by_user_id'] = $userId;
        $actor = $this->access->actor($userId, $actor);
        $document = $this->access->find((int) $actor->current_organization_id, $actor, $document->id, 'payments.transaction.register');
        $expectedContracts = [(int) $document->id => $this->contractLocks->contractIdForPaymentDocument($document)];

        return $this->documents->registerPayment($document, $data['amount'], $data,
            fn (PaymentDocument $locked) => $this->access->assertDocument($locked, $actor, 'payments.transaction.register'), $expectedContracts);
    }

    public function cancel(PaymentDocument $document, string $reason, ?User $user): PaymentDocument
    {
        $actor = $this->access->actor((int) ($user?->id ?? 0), $user);

        return $this->mutate($document, $actor, 'payments.invoice.cancel',
            fn (PaymentDocument $current): PaymentDocument => $this->documents->cancel($current, $reason, $actor));
    }

    public function delete(PaymentDocument $document, User $actor): void
    {
        $this->mutate($document, $actor, 'payments.invoice.delete',
            fn (PaymentDocument $current): bool => $this->documents->delete($current));
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<string, mixed>
     */
    public function bulkAction(int $organizationId, array $ids, string $action, ?User $user, array $payload): array
    {
        $permission = PaymentDocumentAccessService::bulkPermission($action);
        if ($permission === null) {
            throw (new ModelNotFoundException)->setModel(PaymentDocument::class);
        }
        $user = $this->access->actor((int) ($user?->id ?? 0), $user);
        $results = [
            'total_requested' => count($ids),
            'success' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        DB::transaction(function () use ($organizationId, $ids, $action, $user, $permission, $payload, &$results): void {
            $expectedContracts = null;
            if ($action === 'pay') {
                $preflight = $this->access->bulk($organizationId, $user, $ids, $permission);
                $expectedContracts = $this->contractLocks->lockForPaymentDocuments($preflight);
            }
            $sourceSnapshots = [];
            if ($action === 'submit') {
                $preflight = $this->access->bulk($organizationId, $user, $ids, $permission);
                foreach ($preflight as $document) {
                    $sourceSnapshots[$document->id] = $document;
                }
                $this->lockRequestOrderSources($organizationId, $preflight->all());
            }
            $documents = $this->access->bulk($organizationId, $user, $ids, $permission, true);
            if ($action === 'submit') {
                foreach ($documents as $document) {
                    $snapshot = $sourceSnapshots[$document->id] ?? null;
                    if (! $snapshot instanceof PaymentDocument) {
                        throw new \DomainException(trans_message('payments.validation.request_order_conflict'));
                    }
                    $this->assertSourceUnchanged($document, $snapshot);
                }
            }
            if ($expectedContracts !== null) {
                foreach ($documents as $document) {
                    $this->contractLocks->assertContractUnchanged($document, $expectedContracts);
                }
            }

            foreach ($documents as $document) {
                try {
                    $this->applyBulkAction($document, $action, $user, $payload, $expectedContracts);
                    $results['success']++;
                } catch (\DomainException $e) {
                    $results['failed']++;
                    $results['errors'][] = sprintf(
                        trans_message('payments.documents.bulk_item_error'),
                        $document->id,
                        $e->getMessage()
                    );
                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = sprintf(
                        trans_message('payments.documents.bulk_item_failed'),
                        $document->id
                    );
                    Log::error('bulk_action.item_error', [
                        'id' => $document->id,
                        'action' => $action,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        $results['processed'] = $results['success'] + $results['failed'];

        return $results;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function generatePurpose(string $documentType, array $data): string
    {
        return $this->purposeGenerator->generate(PaymentDocumentType::from($documentType), $data);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyBulkAction(PaymentDocument $document, string $action, User $user, array $payload, ?array $expectedContracts): void
    {
        match ($action) {
            'submit' => $this->documents->submit(
                $document,
                $user,
                $this->nullableString($payload['budget_override_reason'] ?? null)
            ),
            'approve' => $this->documents->approve(
                $document,
                $user->id,
                $this->nullableString($payload['budget_override_reason'] ?? null)
            ),
            'cancel' => $this->documents->cancel(
                $document,
                (string) ($payload['reason'] ?? ''),
                $user
            ),
            'schedule' => $this->documents->schedule(
                $document,
                new DateTime((string) $payload['scheduled_at']),
                $user,
                $this->nullableString($payload['budget_override_reason'] ?? null)
            ),
            'pay' => $this->documents->registerPayment($document, (string) $document->remaining_amount, [
                'notes' => trans_message('payments.documents.bulk_payment_note'),
                'created_by_user_id' => $user->id,
                'budget_override_reason' => $this->nullableString($payload['budget_override_reason'] ?? null),
            ], fn (PaymentDocument $locked) => $this->access->assertDocument($locked, $user, 'payments.transaction.register'), $expectedContracts),
            default => null,
        };
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function mutate(PaymentDocument $document, User $actor, string $permission, Closure $operation): mixed
    {
        return DB::transaction(function () use ($document, $actor, $permission, $operation): mixed {
            $organizationId = (int) $actor->current_organization_id;
            $snapshot = $this->access->find($organizationId, $actor, $document->id, $permission);
            $this->lockRequestOrderSources($organizationId, [$snapshot]);
            $current = $this->access->find($organizationId, $actor, $document->id, $permission, true);
            $this->assertSourceUnchanged($current, $snapshot);
            $this->access->assertDocument($current, $actor, $permission);

            return $operation($current);
        });
    }

    private function lockRequestOrderSources(int $organizationId, array $documents): void
    {
        $requestIds = [];
        foreach ($documents as $document) {
            if ($document->document_type === PaymentDocumentType::PAYMENT_ORDER
                && $document->source_type === PaymentDocument::class
                && $document->source_id !== null) {
                $requestIds[] = (int) $document->source_id;
            }
        }
        if ($requestIds === []) {
            return;
        }

        PaymentDocument::query()->forOrganization($organizationId)
            ->where('document_type', PaymentDocumentType::PAYMENT_REQUEST->value)
            ->whereIn('id', array_unique($requestIds))->orderBy('id')
            ->lockForUpdate()->get();
    }

    private function assertSourceUnchanged(PaymentDocument $current, PaymentDocument $snapshot): void
    {
        if ($current->document_type !== $snapshot->document_type
            || $current->source_type !== $snapshot->source_type
            || $current->source_id !== $snapshot->source_id) {
            throw new \DomainException(trans_message('payments.validation.request_order_conflict'));
        }
    }
}
