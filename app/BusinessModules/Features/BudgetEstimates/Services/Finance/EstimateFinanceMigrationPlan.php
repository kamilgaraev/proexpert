<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\Models\ContractEstimateItem;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class EstimateFinanceMigrationPlan
{
    public function __construct(private readonly EstimateFinanceAccess $access, private readonly EstimateFinanceQuery $query) {}

    public function report(User $actor, int $projectId, int $estimateId, int $after = 0, int $limit = 100, ?array $linkIds = null): array
    {
        $estimate = $this->access->estimate($actor, $projectId, $estimateId);
        if ($after < 0 || $limit < 1 || $limit > 500) {
            throw ValidationException::withMessages(['pagination' => trans_message('estimate_finance.invalid')]);
        }
        $links = ContractEstimateItem::query()->where('estimate_id', $estimateId)
            ->where(fn ($builder) => $builder->where('finance_managed', false)->orWhereNull('finance_managed'))
            ->where('id', '>', $after)->orderBy('id')->limit($limit + 1)
            ->when($linkIds !== null, fn ($builder) => $builder->whereIn('id', $linkIds))
            ->with(['contract' => fn ($builder) => $builder->where('organization_id', $estimate->organization_id)->where('project_id', $projectId),
                'estimateItem' => fn ($builder) => $builder->where('estimate_id', $estimateId)])
            ->get();
        $hasMore = $links->count() > $limit;
        $rows = [];
        foreach ($links->take($limit) as $link) {
            $reasons = ['tax_terms_not_recorded', 'price_composition_not_recorded'];
            $blocked = ! $link->contract || ! $link->estimateItem;
            if (! $link->contract) {
                $reasons[] = 'contract_outside_scope_or_missing';
            }
            if (! $link->estimateItem) {
                $reasons[] = 'position_outside_scope_or_missing';
            }
            $side = $link->contract ? $this->query->side($link->contract) : 'unknown';
            if ($side === 'unknown') {
                $reasons[] = 'direction_unknown';
            }
            if (! $link->contract?->currency) {
                $reasons[] = 'currency_unknown';
            }
            if ($link->estimateItem?->is_not_accounted) {
                $reasons[] = 'position_excluded';
            }
            if ($link->estimateItem?->parent_work_id) {
                $reasons[] = 'included_position_requires_review';
            }
            $snapshot = ['link_id' => (int) $link->id, 'estimate_id' => $estimateId, 'contract_id' => (int) $link->contract_id,
                'estimate_item_id' => (int) $link->estimate_item_id, 'quantity' => (string) $link->quantity,
                'amount' => $link->amount, 'amount_without_vat' => $link->amount_without_vat, 'notes' => $link->notes,
                'updated_at' => $link->updated_at?->toISOString(), 'direction' => $side, 'currency' => $link->contract?->currency,
                'contract_updated_at' => $link->contract?->updated_at?->toISOString(), 'position_updated_at' => $link->estimateItem?->updated_at?->toISOString(),
                'reasons' => $reasons];
            $rows[] = ['legacy_link_id' => (int) $link->id, 'status' => $blocked ? 'blocked' : 'requires_review',
                'can_preserve_as_unreviewed' => ! $blocked, 'confirmed_margin_eligible' => false,
                'source_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)), 'source' => $snapshot];
        }

        return ['organization_id' => (int) $estimate->organization_id, 'project_id' => $projectId, 'estimate_id' => $estimateId,
            'revision' => (int) $estimate->finance_revision, 'read_only' => true, 'rows' => $rows,
            'next_cursor' => $hasMore ? $links->take($limit)->last()->id : null];
    }
}
