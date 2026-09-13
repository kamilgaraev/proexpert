<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\BusinessModules\Features\BudgetEstimates\Http\Requests\FinanceInputValidation;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\PreviewEstimateFinanceRequest;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\AdvanceAccountTransaction;
use App\Models\CostCategory;
use App\Models\User;

final class EstimateFinanceOwnCostOptions
{
    public function __construct(private readonly EstimateFinanceAccess $access, private readonly AuthorizationService $authorization) {}

    public function search(User $actor, int $projectId, int $estimateId, array $input): array
    {
        $this->access->estimate($actor, $projectId, $estimateId, true);
        $data = FinanceInputValidation::validate($input, PreviewEstimateFinanceRequest::ownCostOptionsRules());
        $kind = $data['kind'];
        if ($kind === 'documents' && ! $this->authorization->can($actor, 'advance_transactions.view', [
            'context_type' => 'project', 'project_id' => $projectId, 'organization_id' => (int) $actor->current_organization_id,
        ])) {
            return ['kind' => $kind, 'available' => false, 'data' => [], 'next_cursor' => null];
        }
        $query = $kind === 'categories'
            ? CostCategory::query()->where('organization_id', $actor->current_organization_id)->where('is_active', true)
            : AdvanceAccountTransaction::query()->where('organization_id', $actor->current_organization_id)->where('project_id', $projectId)
                ->where('type', AdvanceAccountTransaction::TYPE_EXPENSE)->where('reporting_status', AdvanceAccountTransaction::STATUS_APPROVED)
                ->whereNotNull('approved_at')->where('amount', '>', 0)
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('estimate_finance_own_costs')
                    ->whereColumn('advance_transaction_id', 'advance_account_transactions.id'));
        $search = trim($data['query'] ?? '');
        if ($search !== '') {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(function ($query) use ($kind, $pattern): void {
                if ($kind === 'categories') {
                    $query->where('name', 'ilike', $pattern)->orWhere('code', 'ilike', $pattern);
                } else {
                    $query->where('description', 'ilike', $pattern)->orWhere('document_number', 'ilike', $pattern);
                }
            });
        }
        $columns = $kind === 'categories' ? ['id', 'name', 'code']
            : ['id', 'document_number', 'document_date', 'description', 'amount', 'cost_category_id'];
        $rows = $query->where('id', '>', $data['after'] ?? 0)->orderBy('id')->limit(51)->get($columns);
        $categories = $kind === 'documents' ? CostCategory::query()->where('organization_id', $actor->current_organization_id)
            ->whereIn('id', $rows->pluck('cost_category_id')->filter())->get(['id', 'name', 'is_active'])->keyBy('id') : collect();
        $page = $rows->take(50)->map(static function ($row) use ($kind, $categories): array {
            if ($kind === 'categories') {
                return ['id' => (int) $row->id, 'name' => $row->name, 'code' => $row->code];
            }

            return ['id' => (int) $row->id, 'number' => $row->document_number, 'date' => $row->document_date?->format('Y-m-d'),
                'description' => $row->description, 'amount' => (string) $row->amount, 'currency' => null,
                'cost_category_id' => $row->cost_category_id === null ? null : (int) $row->cost_category_id,
                'category_name' => $categories->get($row->cost_category_id)?->name,
                'category_active' => $categories->get($row->cost_category_id)?->is_active];
        })->values()->all();

        return ['kind' => $kind, 'available' => true, 'data' => $page,
            'next_cursor' => $rows->count() > 50 ? (int) $rows[49]->id : null];
    }
}
