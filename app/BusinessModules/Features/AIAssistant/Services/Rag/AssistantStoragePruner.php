<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotEpoch;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class AssistantStoragePruner
{
    public const CATALOG_PREDICATE = "(source_type = 'core_business_money' AND entity_type = 'core_normative_resource') OR "
        ."(source_type = 'organization_reporting' AND entity_type IN ('approved_estimate_dataset', 'approved_construction_resource', "
        ."'approved_estimate_norm_collection', 'approved_estimate_norm_section', 'approved_estimate_norm', 'approved_estimate_norm_resource', "
        ."'estimate_price_region', 'estimate_price_zone', 'estimate_price_period', 'active_estimate_price_version', "
        ."'active_estimate_price_activation', 'approved_estimate_resource_price'))";

    public function __construct(
        private readonly AssistantStatusSnapshotEpoch $epoch,
        private readonly RagCoverageStateStore $coverage,
    ) {}

    public function prune(int $maxBatches, float $deadline): array
    {
        $result = ['deleted_snapshot_changes' => 0, 'deleted_catalog_sources' => 0, 'deleted_catalog_expected' => 0, 'batches' => 0];
        $ledgerDone = false;
        $catalogDone = false;
        for ($batch = 0; $batch < $maxBatches && microtime(true) < $deadline; $batch++) {
            if (! $ledgerDone) {
                $deleted = $this->epoch->purge(600, 5000);
                $result['deleted_snapshot_changes'] += $deleted;
                $ledgerDone = $deleted === 0;
            }
            if (! $catalogDone && microtime(true) < $deadline) {
                try {
                    $catalog = $this->pruneCatalogBatch($deadline);
                } catch (RagStatusBudgetExceeded) {
                    break;
                }
                $result['deleted_catalog_sources'] += $catalog['sources'];
                $result['deleted_catalog_expected'] += $catalog['expected'];
                $catalogDone = $catalog['sources'] + $catalog['expected'] === 0;
            }
            $result['batches']++;
            if ($ledgerDone && $catalogDone) { break; }
        }

        return $result;
    }

    private function pruneCatalogBatch(float $deadline): array
    {
        $budget = new RagStatusBudget(DB::connection(), max(1, min(5000, (int) (($deadline - microtime(true)) * 1000))));
        return $budget->run(function (callable $checkpoint): array {
            DB::statement("SET LOCAL lock_timeout = '1s'");
            $organizations = [];
            $deleted = [];
            foreach (['sources' => 'ai_rag_sources', 'expected' => 'ai_rag_expected_sources'] as $key => $table) {
                $checkpoint();
                $rows = $this->catalogQuery($table)->select(['id', 'organization_id'])->orderBy('id')->limit(1000)
                    ->lock('FOR UPDATE SKIP LOCKED')->get();
                $checkpoint();
                $deleted[$key] = $rows->isEmpty() ? 0 : DB::table($table)->whereIn('id', $rows->pluck('id'))->delete();
                foreach ($rows as $row) { $organizations[(int) $row->organization_id] = true; }
            }
            $this->coverage->invalidateMany(array_keys($organizations), $checkpoint);

            return $deleted;
        });
    }

    private function catalogQuery(string $table): Builder
    {
        return DB::table($table)->whereRaw('('.self::CATALOG_PREDICATE.')');
    }
}
