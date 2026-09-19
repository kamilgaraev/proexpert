<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Exceptions\PaymentDocumentDeviationBlockedException;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;

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
     * @param  array<string, mixed>  $data
     * @return array{document: PaymentDocument, warnings: array<int, string>}
     */
    public function create(int $organizationId, int $userId, array $data): array
    {
        $data['organization_id'] = $organizationId;
        $data['created_by_user_id'] = $userId;


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
    }

    /**
     * @param  array<string, mixed>  $data
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
     * @param  array<string, mixed>  $data
     */
    public function registerPayment(PaymentDocument $document, int $userId, array $data): PaymentDocument
    {
        if (! isset($data['transaction_date']) && isset($data['payment_date'])) {
            $data['transaction_date'] = $data['payment_date'];
        }

        $data['created_by_user_id'] = $userId;

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
     * @param  array<int, int>  $ids
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
     * @param  array<string, mixed>  $data
     */
    public function generatePurpose(string $documentType, array $data): string
    {
        return $this->purposeGenerator->generate(PaymentDocumentType::from($documentType), $data);
    }

    /**
     * @param  array<string, mixed>  $payload
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
            'pay' => $this->documents->registerPayment($document, (string) $document->remaining_amount, [
                'notes' => trans_message('payments.documents.bulk_payment_note'),
                'created_by_user_id' => $user?->id,
                'budget_override_reason' => $this->nullableString($payload['budget_override_reason'] ?? null),
            ]),
            default => null,
        };
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
