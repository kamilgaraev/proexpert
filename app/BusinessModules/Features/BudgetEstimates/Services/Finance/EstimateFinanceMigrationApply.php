<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\BusinessModules\Features\BudgetEstimates\Http\Requests\FinanceInputValidation;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\SaveEstimateFinanceRequest;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCacheService;
use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class EstimateFinanceMigrationApply
{
    public function __construct(private readonly EstimateFinanceAccess $access, private readonly EstimateFinanceMigrationPlan $plan,
        private readonly EstimateFinanceHistory $history, private readonly EstimateCacheService $cache) {}

    public function apply(User $actor, int $projectId, int $estimateId, array $data): array
    {
        $estimate = $this->access->estimate($actor, $projectId, $estimateId, true);
        $data = FinanceInputValidation::validate($data, SaveEstimateFinanceRequest::migrationRules());
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $projectId, $estimateId, $data, $estimate, $hash): array {
            $locked = Estimate::query()->whereKey($estimate->id)->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $projectId)->lockForUpdate()->firstOrFail();
            $receipt = DB::table('estimate_finance_mutations')->where('estimate_id', $estimateId)->where('mutation_id', $data['mutation_id'])->first();
            if ($receipt) {
                if ($receipt->request_hash !== $hash || (int) $receipt->actor_id !== (int) $actor->id) {
                    throw new ConflictHttpException(trans_message('estimate_finance.conflict'));
                }

                return ['revision' => (int) $receipt->revision, 'replayed' => true];
            }
            if ((int) $locked->finance_revision !== (int) $data['revision']) {
                throw new ConflictHttpException(trans_message('estimate_finance.conflict'));
            }
            $ids = array_column($data['links'], 'legacy_link_id');
            $links = ContractEstimateItem::query()->where('estimate_id', $estimateId)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            Contract::query()->whereIn('id', $links->pluck('contract_id'))->orderBy('id')->lockForUpdate()->get();
            $items = EstimateItem::query()->where('estimate_id', $estimateId)->whereIn('id', $links->pluck('estimate_item_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $report = $this->plan->report($actor, $projectId, $estimateId, 0, 500, $ids);
            $rows = array_column($report['rows'], null, 'legacy_link_id');
            $this->access->editContracts($actor, $locked, [], $links->pluck('contract_id')->all());
            $writes = $keys = [];
            foreach ($data['links'] as $input) {
                $row = $rows[$input['legacy_link_id']] ?? null;
                if (! $row || ! $row['can_preserve_as_unreviewed'] || ! hash_equals($row['source_hash'], $input['source_hash'])) {
                    throw new ConflictHttpException(trans_message('estimate_finance.conflict'));
                }
                $source = $row['source'];
                $item = $items->get($source['estimate_item_id']);
                $key = Uuid::uuid5(Uuid::NAMESPACE_URL, 'most:estimate-finance:legacy:'.$source['link_id'])->toString();
                $keys[] = $key;
                $writes[] = ['key' => $key, 'organization_id' => $locked->organization_id, 'estimate_id' => $estimateId,
                    'estimate_item_id' => $source['estimate_item_id'], 'resource_id' => null, 'contract_id' => $source['contract_id'],
                    'contract_estimate_item_id' => $source['link_id'], 'side' => $source['direction'], 'source' => 'contract',
                    'currency' => $source['currency'] ?? '', 'quantity' => $source['quantity'], 'unit_price' => null,
                    'amount_without_vat' => $source['amount_without_vat'], 'amount_with_vat' => null, 'legacy_amount' => $source['amount'],
                    'vat_rate' => null, 'vat_mode' => 'unknown', 'price_basis' => 'unknown', 'method' => 'total', 'composition_confirmed' => false,
                    'condition_version' => 1, 'estimate_snapshot' => json_encode(['migration_source' => $source,
                        'quantity' => (string) ($item->quantity_total ?? $item->quantity ?? '0'),
                        'estimate_amount' => (string) ($item->total_amount ?? '0'), 'unit_id' => $item->measurement_unit_id], JSON_THROW_ON_ERROR),
                    'notes' => $source['notes'], 'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()];
            }
            if (DB::table('estimate_finance_allocations')->whereIn('contract_estimate_item_id', $ids)->exists()
                || DB::table('estimate_finance_allocations')->whereIn('key', $keys)->exists()) {
                throw new ConflictHttpException(trans_message('estimate_finance.conflict'));
            }
            DB::table('estimate_finance_allocations')->insert($writes);
            DB::table('contract_estimate_items')->where('estimate_id', $estimateId)->whereIn('id', $ids)->update(['finance_managed' => true]);
            $revision = (int) $locked->finance_revision + 1;
            DB::table('estimates')->where('id', $estimateId)->update(['finance_revision' => $revision]);
            $this->history->record($actor, $locked, $data['mutation_id'], $revision, [], $keys);
            DB::table('estimate_finance_mutations')->insert(['estimate_id' => $estimateId, 'mutation_id' => $data['mutation_id'],
                'request_hash' => $hash, 'actor_id' => $actor->id, 'revision' => $revision,
                'changes' => json_encode(['migration' => $report['rows'], 'allocation_keys' => $keys], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

            $this->cache->invalidateStructure($locked);

            return ['revision' => $revision, 'replayed' => false];
        }, 3);
    }
}
