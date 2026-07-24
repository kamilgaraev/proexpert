<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Exceptions\PaymentDocumentDeviationBlockedException;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Contract;
use App\Models\User;
use DateTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function trans_message;

final class PaymentDocumentWorkflowService
{
    public function __construct(
        private readonly PaymentDocumentService $documents,
        private readonly PaymentPurposeGenerator $purposeGenerator,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @return array{document: PaymentDocument, warnings: array<int, string>}
     */
    public function create(int $organizationId, int $userId, array $data): array
    {
        $data['organization_id'] = $organizationId;
        $data['created_by_user_id'] = $userId;
        $data = $this->prepareContractPaymentData($organizationId, $data);

        $warnings = [];

        if (!empty($data['estimate_splits'])) {
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

        if (!empty($data['overprice_justification'])) {
            $document->notes = trim(
                ($document->notes ?? '')
                . "\n\n"
                . trans_message('payments.documents.overprice_justification_note')
                . "\n"
                . $data['overprice_justification']
            );
            $document->saveQuietly();
        }

        $document->load('estimateSplits.estimateItem');

        return [
            'document' => $document,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(PaymentDocument $document, array $data): PaymentDocument
    {
        return $this->documents->update($document, $data);
    }

    public function submit(PaymentDocument $document, ?User $user, ?string $budgetOverrideReason): PaymentDocument
    {
        return $this->documents->submit($document, $user, $budgetOverrideReason);
    }

    public function schedule(
        PaymentDocument $document,
        ?string $scheduledAt,
        ?User $user,
        ?string $budgetOverrideReason
    ): PaymentDocument {
        return $this->documents->schedule(
            $document,
            $scheduledAt !== null ? new DateTime($scheduledAt) : null,
            $user,
            $budgetOverrideReason
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function registerPayment(PaymentDocument $document, int $userId, array $data): PaymentDocument
    {
        if (!isset($data['transaction_date']) && isset($data['payment_date'])) {
            $data['transaction_date'] = $data['payment_date'];
        }

        $data['created_by_user_id'] = $userId;
        $data['amount'] = (float) $data['amount'];

        return $this->documents->registerPayment($document, $data['amount'], $data);
    }

    public function cancel(PaymentDocument $document, string $reason, ?User $user): PaymentDocument
    {
        return $this->documents->cancel($document, $reason, $user);
    }

    public function delete(PaymentDocument $document): void
    {
        $this->documents->delete($document);
    }

    /**
     * @param array<int, int> $ids
     * @return array<string, mixed>
     */
    public function bulkAction(int $organizationId, array $ids, string $action, ?User $user, array $payload): array
    {
        $results = [
            'total_requested' => count($ids),
            'success' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        DB::transaction(function () use ($organizationId, $ids, $action, $user, $payload, &$results): void {
            $documents = PaymentDocument::forOrganization($organizationId)
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get();

            foreach ($documents as $document) {
                try {
                    $this->applyBulkAction($document, $action, $user, $payload);
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
     * @param array<string, mixed> $data
     */
    public function generatePurpose(string $documentType, array $data): string
    {
        return $this->purposeGenerator->generate(PaymentDocumentType::from($documentType), $data);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyBulkAction(PaymentDocument $document, string $action, ?User $user, array $payload): void
    {
        match ($action) {
            'submit' => $this->documents->submit(
                $document,
                $user,
                $this->nullableString($payload['budget_override_reason'] ?? null)
            ),
            'approve' => $this->documents->approve(
                $document,
                $user?->id,
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
            'pay' => $this->documents->registerPayment($document, (float) $document->remaining_amount, [
                'notes' => trans_message('payments.documents.bulk_payment_note'),
                'created_by_user_id' => $user?->id,
                'budget_override_reason' => $this->nullableString($payload['budget_override_reason'] ?? null),
            ]),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function prepareContractPaymentData(int $organizationId, array $data): array
    {
        if (isset($data['contract_id'])) {
            $data['source_id'] ??= $data['contract_id'];
            $data['source_type'] ??= Contract::class;
            $data['invoiceable_id'] ??= $data['contract_id'];
            $data['invoiceable_type'] ??= Contract::class;
        }

        $contractId = $data['invoiceable_id']
            ?? $data['source_id']
            ?? $data['contract_id']
            ?? null;
        $isContractRelated = ($data['invoiceable_type'] ?? null) === Contract::class
            || ($data['source_type'] ?? null) === Contract::class
            || isset($data['contract_id']);

        $contract = null;
        if ($isContractRelated && $contractId) {
            $contract = Contract::query()
                ->whereKey($contractId)
                ->where('organization_id', $organizationId)
                ->first();

            if ($contract instanceof Contract) {
                $data = $this->applyContractPaymentParties($data, $contract);
            }
        }

        if (($data['invoice_type'] ?? null) !== 'advance' || !$isContractRelated || !$contractId || !empty($data['amount'])) {
            return $data;
        }

        if (!$contract instanceof Contract) {
            Log::warning('payment_document.store.contract_not_found', [
                'contract_id' => $contractId,
                'organization_id' => $organizationId,
            ]);

            throw new \DomainException(sprintf(
                trans_message('payments.validation.contract_not_found_by_id'),
                $contractId
            ));
        }

        $amount = $this->resolveContractAdvanceAmount($contract);

        if ($amount === null) {
            Log::warning('payment_document.store.cannot_calculate_advance', [
                'contract_id' => $contractId,
                'planned_advance_amount' => $contract->planned_advance_amount,
                'total_amount_with_gp' => $contract->total_amount_with_gp,
                'total_amount' => $contract->total_amount,
                'base_amount' => $contract->base_amount,
                'is_fixed_amount' => $contract->is_fixed_amount,
            ]);

            throw new \DomainException(sprintf(
                trans_message('payments.validation.advance_amount_auto_detect_failed'),
                $contract->number ?? $contractId
            ));
        }

        $data['amount'] = $amount;

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function applyContractPaymentParties(array $data, Contract $contract): array
    {
        $direction = (string) ($data['direction'] ?? 'outgoing');
        $hasPayer = isset($data['payer_organization_id']) || isset($data['payer_contractor_id']);
        $hasPayee = isset($data['payee_organization_id']) || isset($data['payee_contractor_id']);

        if ($direction === 'incoming') {
            if (!$hasPayer && $contract->contractor_id) {
                $data['payer_contractor_id'] = $contract->contractor_id;
            }

            if (!$hasPayee) {
                $data['payee_organization_id'] = $contract->organization_id;
            }

            return $data;
        }

        if (!$hasPayer) {
            $data['payer_organization_id'] = $contract->organization_id;
        }

        if (!$hasPayee && $contract->contractor_id) {
            $data['payee_contractor_id'] = $contract->contractor_id;
        }

        return $data;
    }

    private function resolveContractAdvanceAmount(Contract $contract): ?float
    {
        if ($contract->planned_advance_amount && $contract->planned_advance_amount > 0) {
            return (float) $contract->planned_advance_amount;
        }

        if ($contract->is_fixed_amount && $contract->total_amount_with_gp !== null && $contract->total_amount_with_gp > 0) {
            return (float) $contract->total_amount_with_gp;
        }

        if ($contract->total_amount && $contract->total_amount > 0) {
            return (float) $contract->total_amount;
        }

        if ($contract->base_amount && $contract->base_amount > 0) {
            return (float) $contract->base_amount;
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
