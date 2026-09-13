<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\BusinessModules\Features\BudgetEstimates\Http\Requests\FinanceInputValidation;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\SaveEstimateFinanceRequest;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\AdvanceAccountTransaction;
use App\Models\CostCategory;
use App\Models\Estimate;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class EstimateFinanceOwnCost
{
    public function __construct(private readonly EstimateFinanceAccess $access, private readonly AuthorizationService $authorization) {}

    public function handle(User $actor, int $projectId, int $estimateId, array $input, bool $save): array
    {
        $this->access->estimate($actor, $projectId, $estimateId, true);
        $data = FinanceInputValidation::validate($input, SaveEstimateFinanceRequest::ownCostRules(! $save));
        if ($data['source_type'] === 'advance_expense' && ! $this->authorization->can($actor, 'advance_transactions.view', [
            'context_type' => 'project', 'project_id' => $projectId, 'organization_id' => (int) $actor->current_organization_id,
        ])) {
            throw new AuthorizationException;
        }
        $requestHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $projectId, $estimateId, $data, $requestHash, $save): array {
            $estimate = Estimate::query()->where('organization_id', $actor->current_organization_id)->where('project_id', $projectId)
                ->whereKey($estimateId)->lockForUpdate()->firstOrFail();
            $receipt = DB::table('estimate_finance_mutations')->where('estimate_id', $estimateId)->where('mutation_id', $data['mutation_id'])->first();
            if ($save && $receipt) {
                if ($receipt->request_hash !== $requestHash || (int) $receipt->actor_id !== (int) $actor->id) {
                    $this->conflict();
                }

                return ['revision' => (int) $receipt->revision, 'replayed' => true, 'cost_key' => $data['cost_key']];
            }
            if ((int) $estimate->finance_revision !== (int) $data['revision']) {
                $this->conflict();
            }
            $existing = DB::table('estimate_finance_own_costs')->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $projectId)->where('key', $data['cost_key'])->lockForUpdate()->first();
            if ($existing) {
                if ((int) $existing->version !== (int) ($data['source_version'] ?? 0)) {
                    $this->conflict();
                }
                if ($existing->source_type !== $data['source_type']
                    || ($existing->source_type === 'advance_expense' && ((int) $existing->advance_transaction_id !== (int) ($data['advance_transaction_id'] ?? 0)
                        || ($data['status'] ?? 'confirmed') !== 'confirmed'))
                    || $existing->currency !== $data['currency'] || $existing->status !== 'confirmed') {
                    $this->invalid();
                }
            } elseif (isset($data['source_version']) || ($data['status'] ?? 'confirmed') !== 'confirmed') {
                $this->conflict();
            }
            $category = CostCategory::query()->where('organization_id', $actor->current_organization_id)
                ->whereKey($data['cost_category_id'])->lockForUpdate()->first();
            if (! $category || ($data['source_type'] === 'manual' && ! $category->is_active)
                || trim($data['basis']) === '' || FinanceDecimal::compare($data['amount'], '0') <= 0) {
                $this->invalid();
            }
            $tax = EstimateFinanceTax::calculate($data, FinanceDecimal::value($data['amount']));
            $amount = $tax['amount_with_vat'] ?? FinanceDecimal::value($data['amount']);
            $snapshot = ['source_type' => $data['source_type'], 'currency' => $data['currency'], 'amount' => $amount,
                'expense_date' => $data['expense_date'], 'basis' => trim($data['basis']), 'category_id' => (int) $category->id,
                'category_name' => $category->name, 'vat_mode' => $tax['vat_mode'], 'vat_rate' => $data['vat_rate'] ?? null,
                'amount_without_vat' => $tax['amount_without_vat'], 'status' => $data['status'] ?? 'confirmed'];
            if ($data['source_type'] === 'advance_expense') {
                $source = AdvanceAccountTransaction::query()->where('organization_id', $actor->current_organization_id)
                    ->where('project_id', $projectId)->whereKey($data['advance_transaction_id'])->lockForUpdate()->first();
                if (! $source || $source->type !== AdvanceAccountTransaction::TYPE_EXPENSE
                    || $source->reporting_status !== AdvanceAccountTransaction::STATUS_APPROVED || ! $source->approved_at
                    || FinanceDecimal::compare((string) $source->amount, $amount) !== 0
                    || (! $category->is_active && (int) $source->cost_category_id !== (int) $category->id)
                    || ($source->cost_category_id !== null && (int) $source->cost_category_id !== (int) $category->id)) {
                    $this->invalid();
                }
                $snapshot['document'] = $source->only(['id', 'organization_id', 'project_id', 'type', 'amount', 'reporting_status',
                    'approved_at', 'approved_by_user_id', 'document_number', 'document_date', 'description', 'cost_category_id']);
                if (DB::table('estimate_finance_own_costs')->where('advance_transaction_id', $source->id)
                    ->when($existing, fn ($query) => $query->where('id', '<>', $existing->id))->exists()) {
                    $this->invalid('own_cost_duplicate');
                }
            }
            if (! $existing && DB::table('estimate_finance_own_costs')->where('key', $data['cost_key'])->exists()) {
                $this->conflict();
            }
            $sourceHash = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
            if (isset($data['source_hash']) && $sourceHash !== $data['source_hash']) {
                $this->conflict();
            }
            if (! $save) {
                return ['operation' => 'own_cost', 'revision' => (int) $estimate->finance_revision, 'cost_key' => $data['cost_key'],
                    'source_hash' => $sourceHash, 'cost' => $snapshot];
            }
            $row = ['key' => $data['cost_key'], 'organization_id' => (int) $actor->current_organization_id, 'project_id' => $projectId,
                'source_type' => $data['source_type'], 'advance_transaction_id' => $data['advance_transaction_id'] ?? null,
                'cost_category_id' => $category->id, 'expense_date' => $data['expense_date'], 'basis' => trim($data['basis']),
                'currency' => $data['currency'], 'amount' => $amount, 'amount_without_vat' => $tax['amount_without_vat'],
                'vat_mode' => $tax['vat_mode'], 'vat_rate' => $data['vat_rate'] ?? null, 'status' => $data['status'] ?? 'confirmed', 'version' => $existing ? (int) $existing->version + 1 : 1,
                'source_hash' => $sourceHash, 'source_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'confirmed_by' => $actor->id, 'confirmed_at' => now(), 'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()];
            if ($existing) {
                $id = $existing->id;
                $row['created_at'] = $existing->created_at;
                DB::table('estimate_finance_own_costs')->where('id', $id)->update($row);
            } else {
                if (DB::table('estimate_finance_own_costs')->insertOrIgnore($row) !== 1) {
                    $this->conflict();
                }
                $id = DB::table('estimate_finance_own_costs')->where('key', $data['cost_key'])->value('id');
            }
            DB::table('estimate_finance_own_cost_versions')->insert(['own_cost_id' => $id, 'version' => $row['version'],
                'mutation_id' => $data['mutation_id'], 'before' => $existing ? json_encode($existing, JSON_THROW_ON_ERROR) : null, 'after' => json_encode($row, JSON_THROW_ON_ERROR),
                'actor_id' => $actor->id, 'created_at' => now()]);
            $revision = (int) $estimate->finance_revision + 1;
            DB::table('estimates')->where('id', $estimateId)->update(['finance_revision' => $revision]);
            DB::table('estimate_finance_mutations')->insert(['estimate_id' => $estimateId, 'mutation_id' => $data['mutation_id'],
                'request_hash' => $requestHash, 'actor_id' => $actor->id, 'revision' => $revision,
                'changes' => json_encode(['operation' => 'own_cost', 'cost_key' => $data['cost_key']], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now()]);

            return ['revision' => $revision, 'replayed' => false, 'cost_key' => $data['cost_key']];
        }, 3);
    }

    private function invalid(string $key = 'own_cost_invalid'): never
    {
        throw ValidationException::withMessages(['cost' => trans_message('estimate_finance.'.$key)]);
    }

    private function conflict(): never
    {
        throw new ConflictHttpException(trans_message('estimate_finance.conflict'));
    }
}
