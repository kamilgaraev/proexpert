<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;
use App\Models\Contract;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ContractRevisionApplicationService
{
    public function __construct(
        private readonly ContractRevisionActivationService $activations,
        private readonly ContractOrganizationViewService $views,
        private readonly ContractAuditedMutationService $mutations,
        private readonly ContractStateEventService $events,
        private readonly SpecificationService $specifications,
    ) {}

    public function retry(User $actor, int $organizationId, int $contractId, int $activationId): array
    {
        $this->activations->authorize($actor, $organizationId);
        $this->views->find($actor, $organizationId, $contractId);
        $instance = DB::table('contract_builder_instances')->where('contract_id', $contractId)->firstOrFail();
        $row = DB::table('contract_revision_activations')->where('instance_id', $instance->id)->where('id', $activationId)->firstOrFail();
        $parties = json_decode(DB::table('contract_builder_revisions')->where('id', $row->revision_id)->value('parties'), true, 512, JSON_THROW_ON_ERROR);
        if (count(array_filter($parties, static fn (array $party): bool => (int) ($party['linked_organization_id'] ?? 0) === $organizationId)) !== 1) {
            throw new AuthorizationException;
        }

        return $this->applyDue($activationId);
    }

    public function applyDue(int $activationId): array
    {
        $activation = DB::table('contract_revision_activations')->where('id', $activationId)->firstOrFail();
        $contractId = (int) DB::table('contract_builder_instances')->where('id', $activation->instance_id)->value('contract_id');
        try {
            return DB::transaction(function () use ($activationId, $contractId): array {
                $contract = Contract::whereKey($contractId)->lockForUpdate()->firstOrFail();
                $row = DB::table('contract_revision_activations')->where('id', $activationId)->lockForUpdate()->firstOrFail();
                if (in_array($row->status, ['applied', 'cancelled'], true) || $row->effective_date > now()->toDateString()) {
                    return $this->activations->present($row);
                }
                $instance = DB::table('contract_builder_instances')->where('id', $row->instance_id)->firstOrFail();
                if (!ContractBuilderWorkflow::canRevise($contract, $instance)
                    || (int) $instance->effective_revision_id !== (int) $row->previous_revision_id) {
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
                $this->compatible($contract, $terms);
                if (!$contract->stateEvents()->where('event_type', 'created')->exists()) {
                    if ($contract->stateEvents()->where('amount_delta', '!=', 0)->exists()) {
                        throw new ContractBuilderException('contracts.revision_history_incompatible', 409);
                    }
                    $this->events->createContractCreatedEvent($contract, null, (int) $row->created_by, Carbon::parse($row->effective_date),
                        ['source' => 'revision_activation_baseline', 'revision_id' => (int) $row->revision_id, 'activation_id' => $activationId, 'basis' => $row->basis]);
                }
                $specification = isset($plan['bases']['works'])
                    ? $this->specifications->applyRevisionPlan($contract, (int) $row->revision_id, $row->effective_date, $plan['works']) : null;
                $beforeAmount = (string) $contract->total_amount;
                $fromStatus = $contract->getRawOriginal('status');
                $terms['status'] = 'active';
                $context = ['revision_id' => (int) $row->revision_id, 'activation_id' => $activationId,
                    'content_hash' => $row->content_hash, 'basis' => $row->basis, 'effective_date' => $row->effective_date, 'clause_bases' => $plan['bases']];
                $this->mutations->update($contract, $terms, 'revision_activated', (int) $row->created_by, $context);
                if ($fromStatus === 'draft') {
                    $this->events->createStatusTransitionEvent($contract, 'activate', 'draft', 'active', $row->basis, (int) $row->created_by);
                }
                $delta = BigDecimal::of((string) $contract->total_amount)->minus($beforeAmount);
                if (!$delta->isZero() || $specification !== null) {
                    $this->events->createAmendedEvent($contract, $specification?->id, $delta->toFloat(), $contract,
                        Carbon::parse($row->effective_date), $context, (int) $row->created_by);
                }
                DB::table('contract_builder_instances')->where('id', $instance->id)->update(['effective_revision_id' => $row->revision_id, 'updated_at' => now()]);
                DB::table('contract_revision_activations')->where('id', $activationId)->update([
                    'status' => 'applied', 'attempts' => $row->attempts + 1, 'last_error' => null, 'applied_at' => now(), 'updated_at' => now(),
                    'result' => json_encode(['specification_id' => $specification?->id, 'work_rows' => count($plan['works']), 'terms' => (object) $terms], JSON_THROW_ON_ERROR),
                ]);

                return $this->activations->present(DB::table('contract_revision_activations')->where('id', $activationId)->firstOrFail());
            });
        } catch (\Throwable $exception) {
            $error = $exception instanceof ContractBuilderException ? $exception->messageKey() : 'contracts.revision_application_failed';
            DB::transaction(function () use ($contractId, $activationId, $error): void {
                Contract::whereKey($contractId)->lockForUpdate()->firstOrFail();
                DB::table('contract_revision_activations')->where('id', $activationId)->whereNotIn('status', ['applied', 'cancelled'])->update([
                    'status' => 'failed', 'last_error' => $error, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now(),
                ]);
            });
            Log::error('contract.revision_application_failed', ['activation_id' => $activationId, 'contract_id' => $contractId, 'exception' => $exception::class]);
            if ($exception instanceof ContractBuilderException) {
                throw $exception;
            }
            throw new ContractBuilderException('contracts.revision_application_failed', 503);
        }
    }

    private function compatible(Contract $contract, array $terms): void
    {
        $documents = \App\BusinessModules\Core\Payments\Models\PaymentDocument::withTrashed()
            ->where(function ($query) use ($contract): void {
                $query->where(static fn ($direct) => $direct->where('invoiceable_type', Contract::class)->where('invoiceable_id', $contract->id))
                    ->orWhere(static fn ($source) => $source->where('source_type', Contract::class)->where('source_id', $contract->id))
                    ->orWhere(static fn ($acts) => $acts->where('invoiceable_type', \App\Models\ContractPerformanceAct::class)
                        ->whereIn('invoiceable_id', $contract->performanceActs()->select('id')));
            });
        if (isset($terms['currency']) && $terms['currency'] !== $contract->currency
            && ($documents->exists() || $contract->performanceActs()->exists() || $contract->completedWorks()->exists())) {
            throw new ContractBuilderException('contracts.revision_history_incompatible', 409);
        }
        $amount = $terms['total_amount'] ?? (string) $contract->total_amount;
        $paidByOrganization = \App\BusinessModules\Core\Payments\Models\PaymentTransaction::whereIn('payment_document_id', $documents->select('id'))
            ->where('status', 'completed')->groupBy('organization_id')->selectRaw('organization_id, SUM(amount) AS paid')->get();
        foreach ($paidByOrganization as $paid) {
            if (BigDecimal::of((string) $paid->getAttribute('paid'))->isGreaterThan($amount)) {
                throw new ContractBuilderException('contracts.revision_history_incompatible', 409);
            }
        }
        $advance = $terms['planned_advance_amount'] ?? (string) ($contract->planned_advance_amount ?? '0');
        if (BigDecimal::of($advance)->isGreaterThan($amount)
            || BigDecimal::of((string) ($contract->actual_advance_amount ?? '0'))->isGreaterThan($advance)
            || BigDecimal::of((string) $contract->performanceActs()->whereIn('status', ['approved', 'signed'])->sum('amount'))->isGreaterThan($amount)) {
            throw new ContractBuilderException('contracts.revision_history_incompatible', 409);
        }
        $start = $terms['start_date'] ?? $contract->getRawOriginal('start_date');
        $end = $terms['end_date'] ?? $contract->getRawOriginal('end_date');
        if ($start !== null && $end !== null && $start > $end) {
            throw new ContractBuilderException('contracts.revision_history_incompatible', 409);
        }
    }
}
