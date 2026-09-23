<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\Exceptions\ContractBuilderException;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

final class ContractBuilderMutationGuard
{
    public function assertLegacy(Contract $contract): void
    {
        if (DB::table('contract_builder_instances')->where('contract_id', $contract->id)->exists()) {
            throw new ContractBuilderException('contracts.builder_revision_required', 409);
        }
    }

    public function assertUpdate(Contract $contract, array $attributes, string $event): void
    {
        $this->assertFinancialUpdate($contract, $attributes);

        if ($event === 'revision_activated' || $event === 'supplementary_document_activated'
            || !DB::table('contract_builder_instances')->where('contract_id', $contract->id)->exists()) {
            return;
        }
        $candidate = clone $contract;
        $candidate->forceFill($attributes);
        $protected = ['organization_id', 'project_id', 'contractor_id', 'supplier_id', 'superior_organization_id', 'contract_side_type',
            'number', 'date', 'subject', 'payment_terms', 'delivery_terms', 'base_amount', 'total_amount', 'currency',
            'gp_percentage', 'gp_coefficient', 'gp_calculation_type', 'warranty_retention_calculation_type',
            'warranty_retention_percentage', 'warranty_retention_coefficient', 'planned_advance_amount', 'start_date', 'end_date',
            'is_fixed_amount', 'is_multi_project', 'is_self_execution', 'payment_terms_days', 'advance_payment_percent'];
        if ($candidate->isDirty($protected) || ($contract->getRawOriginal('status') === 'draft' && $candidate->getAttributes()['status'] === 'active')) {
            throw new ContractBuilderException('contracts.builder_revision_required', 409);
        }
    }

    private function assertFinancialUpdate(Contract $contract, array $attributes): void
    {
        $paymentQuery = PaymentDocument::withTrashed()->where(function ($query) use ($contract): void {
            $query->where(static fn ($direct) => $direct
                ->where('invoiceable_type', Contract::class)
                ->where('invoiceable_id', $contract->id))
                ->orWhere(static fn ($source) => $source
                    ->where('source_type', Contract::class)
                    ->where('source_id', $contract->id))
                ->orWhere(static fn ($acts) => $acts
                    ->where('invoiceable_type', ContractPerformanceAct::class)
                    ->whereIn('invoiceable_id', $contract->performanceActs()->select('id')));
        });
        $hasPayments = (clone $paymentQuery)->exists();
        $paidAmount = BigDecimal::of((string) ((clone $paymentQuery)->where('status', '!=', 'cancelled')->sum('paid_amount') ?? '0'))
            ->toScale(2, RoundingMode::HalfUp);
        $completedByOrganization = PaymentTransaction::query()
            ->whereIn('payment_document_id', (clone $paymentQuery)->select('id'))
            ->where('status', 'completed')
            ->groupBy('organization_id')
            ->selectRaw('organization_id, SUM(amount) AS paid')
            ->get();
        foreach ($completedByOrganization as $organizationPayment) {
            $organizationPaid = BigDecimal::of((string) $organizationPayment->getAttribute('paid'))
                ->toScale(2, RoundingMode::HalfUp);
            if ($organizationPaid->isGreaterThan($paidAmount)) {
                $paidAmount = $organizationPaid;
            }
        }
        $advanceAmount = BigDecimal::of((string) ((clone $paymentQuery)
            ->where('status', '!=', 'cancelled')
            ->where(function ($query): void {
                $query->where('invoice_type', 'advance')
                    ->orWhere('metadata->contract_payment_type', 'advance');
            })
            ->sum('paid_amount') ?? '0'))->toScale(2, RoundingMode::HalfUp);
        $approvedActsAmount = BigDecimal::of((string) ($contract->performanceActs()
            ->where(function ($query): void {
                $query->where('is_approved', true)
                    ->orWhereIn('status', ['approved', 'signed']);
            })
            ->sum('amount') ?? '0'))->toScale(2, RoundingMode::HalfUp);
        $hasFinancialHistory = $hasPayments
            || $completedByOrganization->isNotEmpty()
            || $contract->performanceActs()->exists()
            || $contract->completedWorks()->exists();

        if (array_key_exists('currency', $attributes)
            && $attributes['currency'] !== $contract->currency
            && $hasFinancialHistory) {
            throw new ContractBuilderException('contracts.financial_history_conflict', 409);
        }

        $minimumTotal = BigDecimal::of((string) ($contract->actual_advance_amount ?? '0'))->toScale(2, RoundingMode::HalfUp);
        if ($paidAmount->isGreaterThan($minimumTotal)) {
            $minimumTotal = $paidAmount;
        }
        if ($approvedActsAmount->isGreaterThan($minimumTotal)) {
            $minimumTotal = $approvedActsAmount;
        }
        $candidateTotal = BigDecimal::of((string) ($attributes['total_amount'] ?? '0'))->toScale(2, RoundingMode::HalfUp);
        if (array_key_exists('total_amount', $attributes)
            && ! $candidateTotal->isEqualTo(BigDecimal::of((string) ($contract->total_amount ?? '0'))->toScale(2, RoundingMode::HalfUp))
            && $candidateTotal->isLessThan($minimumTotal)) {
            throw new ContractBuilderException('contracts.financial_history_conflict', 409);
        }

        $minimumAdvance = BigDecimal::of((string) ($contract->actual_advance_amount ?? '0'))->toScale(2, RoundingMode::HalfUp);
        if ($advanceAmount->isGreaterThan($minimumAdvance)) {
            $minimumAdvance = $advanceAmount;
        }
        $candidateAdvance = BigDecimal::of((string) ($attributes['planned_advance_amount'] ?? '0'))->toScale(2, RoundingMode::HalfUp);
        $plannedAdvanceForValidation = array_key_exists('planned_advance_amount', $attributes)
            ? $candidateAdvance
            : BigDecimal::of((string) ($contract->planned_advance_amount ?? '0'))->toScale(2, RoundingMode::HalfUp);
        if (array_key_exists('planned_advance_amount', $attributes)
            && ! $candidateAdvance->isEqualTo(BigDecimal::of((string) ($contract->planned_advance_amount ?? '0'))->toScale(2, RoundingMode::HalfUp))
            && $candidateAdvance->isLessThan($minimumAdvance)) {
            throw new ContractBuilderException('contracts.financial_history_conflict', 409);
        }

        if (array_key_exists('actual_advance_amount', $attributes)
            && ! BigDecimal::of((string) ($attributes['actual_advance_amount'] ?? '0'))->toScale(2, RoundingMode::HalfUp)
                ->isEqualTo(BigDecimal::of((string) ($contract->actual_advance_amount ?? '0'))->toScale(2, RoundingMode::HalfUp))
            && ! BigDecimal::of((string) ($attributes['actual_advance_amount'] ?? '0'))->toScale(2, RoundingMode::HalfUp)
                ->isEqualTo($advanceAmount)) {
            throw new ContractBuilderException('contracts.financial_history_conflict', 409);
        }

        $actualAdvanceForValidation = array_key_exists('actual_advance_amount', $attributes)
            ? BigDecimal::of((string) ($attributes['actual_advance_amount'] ?? '0'))->toScale(2, RoundingMode::HalfUp)
            : BigDecimal::of((string) ($contract->actual_advance_amount ?? '0'))->toScale(2, RoundingMode::HalfUp);
        $plannedAdvanceValue = array_key_exists('planned_advance_amount', $attributes)
            ? $attributes['planned_advance_amount']
            : $contract->planned_advance_amount;
        if ($plannedAdvanceValue !== null
            && $actualAdvanceForValidation->isGreaterThan(
                BigDecimal::of((string) $plannedAdvanceValue)->toScale(2, RoundingMode::HalfUp)
            )) {
            throw new ContractBuilderException('contracts.financial_history_conflict', 409);
        }

        $totalForAdvance = array_key_exists('total_amount', $attributes) ? $candidateTotal
            : BigDecimal::of((string) ($contract->total_amount ?? '0'))->toScale(2, RoundingMode::HalfUp);
        if ((array_key_exists('planned_advance_amount', $attributes) || array_key_exists('total_amount', $attributes))
            && $plannedAdvanceForValidation->isGreaterThan($totalForAdvance)) {
            throw new ContractBuilderException('contracts.financial_history_conflict', 409);
        }
    }
}
