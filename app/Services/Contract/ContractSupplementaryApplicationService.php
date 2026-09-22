<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;
use App\Models\Contract;
use App\Models\ContractSupplementaryDocument;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ContractSupplementaryApplicationService
{
    public function __construct(
        private readonly ContractSupplementaryActivationService $activations,
        private readonly ContractOrganizationViewService $views,
        private readonly ContractAuditedMutationService $mutations,
        private readonly ContractStateEventService $events,
        private readonly SpecificationService $specifications,
        private readonly ContractRevisionApplicationService $revisions,
    ) {}

    public function retry(User $actor, int $organizationId, int $contractId, int $documentId, int $activationId): array
    {
        $this->activations->authorize($actor, $organizationId);
        $this->views->find($actor, $organizationId, $contractId);
        $document = DB::table('contract_supplementary_documents')->where('contract_id', $contractId)->where('id', $documentId)->firstOrFail();
        $row = DB::table('contract_supplementary_activations')->where('document_id', $document->id)->where('id', $activationId)->firstOrFail();
        $parties = json_decode($document->parties, true, 512, JSON_THROW_ON_ERROR);
        if (count(array_filter($parties, static fn (array $party): bool => (int) ($party['linked_organization_id'] ?? 0) === $organizationId)) !== 1) {
            throw new AuthorizationException;
        }

        return $this->applyDue($activationId);
    }

    public function applyDue(int $activationId): array
    {
        $activation = DB::table('contract_supplementary_activations')->where('id', $activationId)->firstOrFail();
        $document = DB::table('contract_supplementary_documents')->where('id', $activation->document_id)->firstOrFail();
        $contractId = (int) $document->contract_id;
        try {
            return DB::transaction(function () use ($activationId, $contractId, $document): array {
                $contract = Contract::whereKey($contractId)->lockForUpdate()->firstOrFail();
                $row = DB::table('contract_supplementary_activations')->where('id', $activationId)->lockForUpdate()->firstOrFail();
                if (in_array($row->status, ['applied', 'cancelled'], true) || $row->effective_date > now()->toDateString()) {
                    return $this->activations->present($row);
                }
                $lockedDocument = DB::table('contract_supplementary_documents')->where('id', $document->id)->lockForUpdate()->firstOrFail();
                if ($lockedDocument->status !== 'draft') {
                    throw new ContractBuilderException('contracts.activation_conflict', 409);
                }
                $lastApplied = DB::table('contract_supplementary_documents')->where('contract_id', $contractId)
                    ->where('status', 'applied')->orderByDesc('id')->value('id');
                $lastAppliedId = $lastApplied === null ? null : (int) $lastApplied;
                if ($lastAppliedId !== ($row->previous_document_id === null ? null : (int) $row->previous_document_id)) {
                    throw new ContractBuilderException('contracts.activation_conflict', 409);
                }
                $plan = json_decode($row->plan, true, 512, JSON_THROW_ON_ERROR);
                $terms = $plan['terms'];
                $previous = json_decode($row->previous_terms, true, 512, JSON_THROW_ON_ERROR);
                foreach ($previous as $field => $value) {
                    if ($contract->getRawOriginal($field) !== $value) {
                        throw new ContractBuilderException('contracts.activation_conflict', 409);
                    }
                }
                $this->revisions->assertTermsCompatible($contract, $terms);
                $specification = isset($plan['bases']['works'])
                    ? $this->specifications->applyAgreementPlan($contract, (int) $lockedDocument->id, $row->effective_date, $plan['works']) : null;
                $beforeAmount = (string) $contract->total_amount;
                $context = [
                    'supplementary_document_id' => (int) $lockedDocument->id, 'activation_id' => $activationId,
                    'content_hash' => $row->content_hash, 'basis' => $row->basis,
                    'effective_date' => $row->effective_date, 'clause_bases' => $plan['bases'],
                ];
                $this->mutations->update($contract, $terms, 'supplementary_document_activated', (int) $row->created_by, $context);
                $delta = BigDecimal::of((string) $contract->total_amount)->minus($beforeAmount);
                $model = ContractSupplementaryDocument::query()->findOrFail($lockedDocument->id);
                if (!$delta->isZero() || $specification !== null) {
                    $this->events->createAmendedEvent($contract, $specification?->id, $delta->toFloat(), $model,
                        Carbon::parse($row->effective_date), $context, (int) $row->created_by);
                }
                DB::table('contract_supplementary_documents')->where('id', $lockedDocument->id)->update([
                    'status' => 'applied', 'applied_at' => now(),
                    'change_amount' => $delta->isZero() ? null : (string) $delta,
                    'updated_at' => now(),
                ]);
                DB::table('contract_supplementary_activations')->where('id', $activationId)->update([
                    'status' => 'applied', 'attempts' => $row->attempts + 1, 'last_error' => null, 'applied_at' => now(), 'updated_at' => now(),
                    'result' => json_encode([
                        'specification_id' => $specification?->id, 'work_rows' => count($plan['works']),
                        'terms' => (object) $terms, 'change_amount' => $delta->isZero() ? null : (string) $delta,
                    ], JSON_THROW_ON_ERROR),
                ]);

                return $this->activations->present(DB::table('contract_supplementary_activations')->where('id', $activationId)->firstOrFail());
            });
        } catch (\Throwable $exception) {
            $error = $exception instanceof ContractBuilderException ? $exception->messageKey() : 'contracts.supplementary_application_failed';
            DB::transaction(function () use ($contractId, $activationId, $error): void {
                Contract::whereKey($contractId)->lockForUpdate()->firstOrFail();
                DB::table('contract_supplementary_activations')->where('id', $activationId)->whereNotIn('status', ['applied', 'cancelled'])->update([
                    'status' => 'failed', 'last_error' => $error, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now(),
                ]);
            });
            Log::error('contract.supplementary_application_failed', [
                'activation_id' => $activationId, 'contract_id' => $contractId, 'exception' => $exception::class,
            ]);
            if ($exception instanceof ContractBuilderException) {
                throw $exception;
            }
            throw new ContractBuilderException('contracts.supplementary_application_failed', 503);
        }
    }
}
