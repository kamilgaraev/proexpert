<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\BusinessModules\Features\BudgetEstimates\Http\Requests\FinanceInputValidation;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\SaveEstimateFinanceRequest;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCacheService;
use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\Estimate;
use App\Models\EstimateFinanceAllocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class EstimateFinanceService
{
    public function __construct(
        private readonly EstimateFinanceAccess $access,
        private readonly EstimateFinanceQuery $query,
        private readonly EstimateFinanceCalculator $calculator,
        private readonly EstimateCacheService $cache,
        private readonly EstimateFinanceHistory $history,
        private readonly EstimateFinanceAcceptedVolume $acceptedVolume,
        private readonly EstimateFinanceRemainder $remainder,
        private readonly EstimateFinanceExecution $execution,
        private readonly EstimateFinanceExecutionSummary $executionSummary,
        private readonly EstimateFinanceProjectExecution $projectExecution,
        private readonly EstimateFinanceCashSources $cashSources,
        private readonly EstimateFinanceProjectCash $projectCash,
        private readonly EstimateFinanceOwnCost $ownCost,
        private readonly EstimateFinanceCashDistribution $cashDistribution,
    ) {}

    public function report(User $actor, int $projectId, int $estimateId, string $basis = 'with_vat', string $view = 'plan'): array
    {
        if (! in_array($view, ['plan', 'execution', 'cash'], true)) {
            $this->invalid();
        }
        $estimate = $this->access->estimate($actor, $projectId, $estimateId);

        return DB::transaction(function () use ($actor, $estimate, $basis, $view): array {
            $locked = Estimate::query()->whereKey($estimate->id)->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $estimate->project_id)->sharedLock()->firstOrFail();

            return $this->reportEstimate($actor, $locked, $basis, $view);
        }, 3);
    }

    public function projectReport(User $actor, int $projectId, string $basis, bool $includeDetails = false, string $view = 'plan'): array
    {
        if (! in_array($view, ['plan', 'execution', 'cash'], true)) {
            $this->invalid();
        }
        $this->access->project($actor, $projectId);

        return DB::transaction(function () use ($actor, $projectId, $basis, $includeDetails, $view): array {
            $reports = [];
            foreach (Estimate::query()->where('organization_id', $actor->current_organization_id)->where('project_id', $projectId)->orderBy('id')->sharedLock()->cursor() as $estimate) {
                $report = $this->reportEstimate($actor, $estimate, $basis, $view);
                if (! $includeDetails && $view === 'plan') {
                    unset($report['rows'], $report['sections']);
                }
                $reports[] = $report;
            }
            $totals = [];
            foreach ($reports as $report) {
                foreach ($report['totals'] as $total) {
                    $currency = $total['currency'];
                    if (! isset($totals[$currency])) {
                        $totals[$currency] = $total;

                        continue;
                    }
                    foreach (['revenue', 'cost', 'own_cost', 'contract_cost', 'complete_margin'] as $field) {
                        $totals[$currency][$field] = FinanceDecimal::add($totals[$currency][$field], $total[$field]);
                    }
                    $totals[$currency]['incomplete_count'] += $total['incomplete_count'];
                }
            }

            $execution = $view === 'execution' ? $this->projectExecution->combine($reports, $basis, $this->access->canViewExecution($actor, $projectId)) : null;
            $cash = $view === 'cash' ? $this->projectCash->combine($reports, $this->access->canViewCash($actor, $projectId)) : null;
            if (! $includeDetails) {
                foreach ($reports as &$report) {
                    unset($report['rows'], $report['sections']);
                }
                unset($report);
            }

            return ['basis' => $basis, 'view' => $view, 'estimates' => $reports, 'totals' => array_values($totals)]
                + ($view === 'execution' ? ['execution' => $execution] : [])
                + ($view === 'cash' ? ['cash' => $cash] : []);
        }, 3);
    }

    public function itemReport(User $actor, int $projectId, int $estimateId, int $itemId, string $basis): array
    {
        $report = $this->report($actor, $projectId, $estimateId, $basis);
        $rows = array_column($report['rows'], null, 'key');
        $key = 'i:'.$itemId;
        if (! isset($rows[$key])) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(\App\Models\EstimateItem::class, [$itemId]);
        }
        $selected = [$key => true];
        $parent = $rows[$key]['parent_key'];
        while ($parent && isset($rows[$parent]) && ! isset($selected[$parent])) {
            $selected[$parent] = true;
            $parent = $rows[$parent]['parent_key'];
        }
        $children = [];
        foreach ($rows as $row) {
            if ($row['parent_key']) {
                $children[$row['parent_key']][] = $row['key'];
            }
        }
        $queue = array_keys($selected);
        for ($index = 0; $index < count($queue); $index++) {
            foreach ($children[$queue[$index]] ?? [] as $child) {
                if (! isset($selected[$child])) {
                    $selected[$child] = true;
                    $queue[] = $child;
                }
            }
        }

        return ['item' => $rows[$key], 'rows' => array_values(array_intersect_key($rows, $selected)), 'contracts' => $report['contracts'],
            'revision' => $report['revision'], 'basis' => $basis, 'can_edit' => $report['can_edit']];
    }

    private function reportEstimate(User $actor, Estimate $estimate, string $basis, string $view = 'plan'): array
    {
        if (! in_array($basis, ['with_vat', 'without_vat'], true)) {
            $this->invalid();
        }
        $targets = $this->query->targets($estimate);
        $allocations = $this->query->allocations($estimate);
        $canViewExecution = $this->access->canViewExecution($actor, (int) $estimate->project_id);
        $accepted = $canViewExecution ? $this->remainder->acceptedFacts($estimate, array_column($allocations, 'key')) : [];
        foreach ($allocations as &$allocation) {
            $allocation['accepted_basis'] = $canViewExecution ? ($accepted[$allocation['key']] ?? null) : null;
            if (! $canViewExecution) {
                $allocation['condition_basis'] = null;
            }
        }
        unset($allocation);
        $calculation = $this->calculator->calculate($targets, $allocations, $basis);
        $sections = $estimate->sections()->get(['id', 'parent_section_id', 'name'])->toArray();
        $sectionRows = [];
        $parents = array_column($sections, 'parent_section_id', 'id');
        foreach ($calculation['rows'] as $row) {
            if ($row['parent_key']) {
                continue;
            }
            $id = $row['section_id'];
            $seen = [];
            while ($id && ! isset($seen[$id])) {
                $seen[$id] = true;
                $sectionRows[$id][] = $row;
                $id = $parents[$id] ?? null;
            }
        }
        foreach ($sections as &$section) {
            $section['totals'] = $this->calculator->totals($sectionRows[$section['id']] ?? []);
        }
        unset($section);
        $contracts = $this->query->contracts($estimate);
        $contractSums = [];
        foreach ($allocations as $allocation) {
            $target = $targets[$allocation['target_key']] ?? null;
            if (! $target || $target['excluded'] || ! $allocation['contract_id']) {
                continue;
            }
            $id = $allocation['contract_id'];
            $contractSums[$id] ??= ['amount' => '0.00', 'unpriced_count' => 0];
            $amount = $allocation[$basis === 'with_vat' ? 'amount_with_vat' : 'amount_without_vat'] ?? null;
            if ($amount === null || ($allocation['side'] === 'revenue' && $target['parent_key'])) {
                $contractSums[$id]['unpriced_count']++;
            } else {
                $contractSums[$id]['amount'] = FinanceDecimal::add($contractSums[$id]['amount'], $amount);
            }
        }
        foreach ($contracts as &$contract) {
            $contract += $contractSums[$contract['id']] ?? ['amount' => '0.00', 'unpriced_count' => 0];
            $contract['linked'] = isset($contractSums[$contract['id']]);
        }
        unset($contract);

        $execution = null;
        if ($view === 'execution') {
            $execution = $canViewExecution ? $this->execution->report($estimate, $contracts)
                : ['available' => false, 'rows' => null, 'documents' => null];
            if ($canViewExecution) {
                $execution['summary'] = $this->executionSummary->calculate($execution['rows'], $allocations, $targets, $sections, $basis);
            }
        }

        return $calculation + [
            'estimate_id' => (int) $estimate->id, 'name' => $estimate->name, 'number' => $estimate->number,
            'revision' => (int) $estimate->finance_revision, 'basis' => $basis,
            'limit' => $basis === 'with_vat' ? $estimate->total_amount_with_vat : $estimate->total_amount,
            'sections' => $sections,
            'contracts' => $contracts, 'can_edit' => $this->access->can($actor, (int) $estimate->project_id, true),
            'can_view_execution' => $canViewExecution,
            'view' => $view,
            'execution' => $execution,
            'cash' => $view !== 'cash' ? null : ($this->access->canViewCash($actor, (int) $estimate->project_id)
                ? $this->cashSources->report($estimate, $contracts)
                : ['available' => false, 'scope' => 'linked_contracts', 'sources' => null, 'documents' => null]),
        ];
    }

    public function history(User $actor, int $projectId, int $estimateId, int $afterId = 0): array
    {
        $estimate = $this->access->estimate($actor, $projectId, $estimateId);

        $history = $this->history->forEstimate($estimate, max(0, $afterId));
        if (! $this->access->canViewExecution($actor, (int) $estimate->project_id)) {
            foreach ($history['data'] as &$entry) {
                foreach (['before', 'after'] as $snapshot) {
                    if (is_array($entry[$snapshot])) {
                        unset($entry[$snapshot]['accepted_basis'], $entry[$snapshot]['condition_basis']);
                    }
                }
            }
            unset($entry);
        }

        return $history;
    }

    public function preview(User $actor, int $projectId, int $estimateId, array $input): array
    {
        if (($input['operation'] ?? null) === 'own_cost') {
            return $this->ownCost->handle($actor, $projectId, $estimateId, $input, false);
        }
        if (($input['operation'] ?? null) === 'cash_distribution') {
            return $this->cashDistribution->handle($actor, $projectId, $estimateId, $input, false);
        }
        $estimate = $this->access->estimate($actor, $projectId, $estimateId, true);
        if (($input['preview_operation'] ?? null) === 'source_amount') {
            $data = FinanceInputValidation::validate($input, \App\BusinessModules\Features\BudgetEstimates\Http\Requests\PreviewEstimateFinanceRequest::sourceRules());
            $targets = $this->query->targets($estimate);

            return ['revision' => (int) $estimate->finance_revision, 'currency' => 'RUB',
                'amount_without_vat' => EstimateFinanceSelection::amount($targets, $data['item_ids']),
                'items_count' => count(EstimateFinanceSelection::rootKeys($targets, $data['item_ids']))];
        }
        $data = FinanceInputValidation::validate($input, SaveEstimateFinanceRequest::inputRules());
        if ((int) $data['revision'] !== (int) $estimate->finance_revision) {
            throw new ConflictHttpException(trans_message('estimate_finance.conflict'));
        }
        $targets = $this->query->targets($estimate);
        if (($data['preview_operation'] ?? 'total') === 'estimate_prices') {
            foreach ($data['lines'] as &$line) {
                if (! ($line['adopt_estimate_price'] ?? false)) {
                    continue;
                }
                $target = $targets[$line['target_key']] ?? null;
                if (! $target || $line['source'] === 'included' || FinanceDecimal::compare($target['quantity'], '0') <= 0
                    || $line['price_basis'] === 'unknown' || $line['currency'] !== 'RUB') {
                    $this->invalid();
                }
                $price = FinanceDecimal::divide($target['estimate_amount'], $target['quantity'], 8);
                if ($line['price_basis'] === 'with_vat') {
                    if (! isset($line['vat_rate'])) {
                        $this->invalid();
                    }
                    $price = FinanceDecimal::multiply($price, FinanceDecimal::add('1', FinanceDecimal::divide($line['vat_rate'], '100', 8)), 8);
                }
                $line['method'] = 'unit';
                $line['unit_price'] = $price;
                $line['amount'] = null;
            }
            unset($line);

            $this->normalize($actor, $estimate, $data, $targets);

            return $data;
        }
        if (! isset($data['expected_total'])) {
            $this->invalid('total');
        }
        $weights = [];
        foreach ($data['lines'] as $line) {
            if (! in_array($line['key'], $data['total_line_keys'], true)) {
                continue;
            }
            $target = $targets[$line['target_key']] ?? null;
            if (! $target || $line['source'] === 'included' || FinanceDecimal::compare($target['quantity'], '0') <= 0) {
                $this->invalid();
            }
            $weights[$line['key']] = FinanceDecimal::divide(
                FinanceDecimal::multiply($target['estimate_amount'], $line['quantity'], 16), $target['quantity'], 16,
            );
        }
        $amounts = FinanceDecimal::allocate($data['expected_total'], $weights);
        foreach ($data['lines'] as &$line) {
            if (! isset($amounts[$line['key']])) {
                continue;
            }
            $line['method'] = 'total';
            $line['amount'] = $amounts[$line['key']];
            $line['unit_price'] = null;
        }
        unset($line);
        $this->normalize($actor, $estimate, $data, $targets);

        return $data;
    }

    public function save(User $actor, int $projectId, int $estimateId, array $input): array
    {
        if (($input['operation'] ?? null) === 'own_cost') {
            return $this->ownCost->handle($actor, $projectId, $estimateId, $input, true);
        }
        if (($input['operation'] ?? null) === 'cash_distribution') {
            return $this->cashDistribution->handle($actor, $projectId, $estimateId, $input, true);
        }
        $estimate = $this->access->estimate($actor, $projectId, $estimateId, true);
        $data = FinanceInputValidation::validate($input, SaveEstimateFinanceRequest::inputRules());
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $estimate, $data, $hash): array {
            $estimate = Estimate::query()->whereKey($estimate->id)->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $estimate->project_id)->lockForUpdate()->firstOrFail();
            $receipt = DB::table('estimate_finance_mutations')->where('estimate_id', $estimate->id)->where('mutation_id', $data['mutation_id'])->first();
            if ($receipt) {
                if ($receipt->request_hash !== $hash || (int) $receipt->actor_id !== (int) $actor->id) {
                    throw new ConflictHttpException(trans_message('estimate_finance.conflict'));
                }

                return ['revision' => (int) $receipt->revision, 'replayed' => true];
            }
            if ((int) $estimate->finance_revision !== (int) $data['revision']) {
                throw new ConflictHttpException(trans_message('estimate_finance.conflict'));
            }
            $estimate->items()->orderBy('id')->lockForUpdate()->get();
            $preparedResources = $this->prepareResources($estimate, $data);
            $mappingChanges = $this->mapResources($estimate, $data);
            $targets = $this->query->targets($estimate);
            $normalized = $this->normalize($actor, $estimate, $data, $targets);
            $existing = EstimateFinanceAllocation::query()->where('estimate_id', $estimate->id)->get();
            $this->validateResourceChanges($data, $targets, $normalized, $existing->toArray());
            $before = [];
            $retainedKeys = array_fill_keys(array_column($normalized, 'key'), true);
            $selectedKeys = array_fill_keys($data['target_keys'], true);
            $existingByKey = $existing->keyBy('key');
            $deletedIds = [];
            foreach ($existing as $row) {
                $key = $row->resource_id ? 'r:'.$row->resource_id : 'i:'.$row->estimate_item_id;
                if (isset($selectedKeys[$key])) {
                    $before[] = $row->toArray();
                    if (! isset($retainedKeys[$row->key])) {
                        $deletedIds[] = $row->id;
                    }
                }
            }
            foreach (array_chunk($deletedIds, 500) as $ids) {
                if (DB::table('estimate_finance_cash_allocations')->whereIn('allocation_id', $ids)->exists()) {
                    $this->invalid('cash_linked');
                }
                EstimateFinanceAllocation::query()->where('estimate_id', $estimate->id)->whereIn('id', $ids)->delete();
            }
            $writes = ['created' => [], 'updated' => []];
            $timestamp = now()->toDateTimeString();
            foreach ($normalized as $row) {
                $previous = $existingByKey->get($row['key']);
                $allocation = new EstimateFinanceAllocation($row);
                $allocation->condition_version = (int) ($previous?->condition_version ?? 0) + 1;
                $allocation->created_at = $previous?->created_at ?? $timestamp;
                $allocation->updated_at = $timestamp;
                $action = $previous === null ? 'created' : 'updated';
                $writes[$action][] = $allocation->getAttributes();
                if (count($writes[$action]) === 500) {
                    $this->writeAllocations($writes[$action], $action === 'created');
                    $writes[$action] = [];
                }
            }
            foreach ($writes as $action => $rows) {
                $this->writeAllocations($rows, $action === 'created');
            }
            $this->projectLinks($estimate, $data['target_keys']);
            $revision = (int) $estimate->finance_revision + 1;
            DB::table('estimates')->where('id', $estimate->id)->update(['finance_revision' => $revision]);
            $this->history->record($actor, $estimate, $data['mutation_id'], $revision, $before, array_column($normalized, 'key'));
            DB::table('estimate_finance_mutations')->insert([
                'estimate_id' => $estimate->id, 'mutation_id' => $data['mutation_id'], 'request_hash' => $hash,
                'actor_id' => $actor->id, 'revision' => $revision,
                'changes' => json_encode(['before' => $before, 'after' => $normalized, 'resource_mappings' => $mappingChanges, 'prepared_resources' => $preparedResources], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->cache->invalidateStructure($estimate);

            return ['revision' => $revision, 'replayed' => false];
        }, 3);
    }

    private function normalize(User $actor, Estimate $estimate, array $data, array $targets): array
    {
        $selectedKeys = array_fill_keys($data['target_keys'], true);
        $totalKeys = array_fill_keys($data['total_line_keys'] ?? [], true);
        $this->access->editContracts($actor, $estimate, $data['target_keys'],
            array_values(array_filter(array_column($data['lines'], 'contract_id'))));
        foreach ($data['target_keys'] as $key) {
            if (! isset($targets[$key]) || $targets[$key]['excluded']) {
                $this->invalid();
            }
        }
        $contractIds = array_filter(array_column($data['lines'], 'contract_id'));
        $contractIds = array_merge($contractIds, ContractEstimateItem::query()->where('estimate_id', $estimate->id)->pluck('contract_id')->all(),
            EstimateFinanceAllocation::query()->where('estimate_id', $estimate->id)->whereNotNull('contract_id')->pluck('contract_id')->all());
        $contracts = Contract::query()->where('organization_id', $estimate->organization_id)->where('project_id', $estimate->project_id)
            ->whereIn('id', array_unique($contractIds))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $rows = [];
        $quantities = [];
        $knownKeys = EstimateFinanceAllocation::query()->whereIn('key', array_column($data['lines'], 'key'))->get()->keyBy('key');
        $cashLinked = DB::table('estimate_finance_cash_allocations')->whereIn('allocation_id', $knownKeys->pluck('id'))->pluck('allocation_id')->flip();
        $retiredKeys = DB::table('estimate_finance_condition_versions')->whereIn('allocation_key', array_column($data['lines'], 'key'))
            ->where('action', 'deleted')->pluck('allocation_key')->flip();
        $legacyLinks = ContractEstimateItem::query()->where('estimate_id', $estimate->id)->where('finance_managed', false)
            ->whereIn('id', array_column($data['lines'], 'legacy_link_id'))->get()->keyBy('id');
        $total = '0.00';
        $bases = [];
        foreach ($data['lines'] as $line) {
            $target = $targets[$line['target_key']] ?? null;
            if ($target && (($target['representation_needs_review'] ?? false) || ($target['represented_by_item_id'] ?? null))) {
                $this->invalid('representation');
            }
            if (! $target || ! isset($selectedKeys[$line['target_key']])
                || (FinanceDecimal::compare($line['quantity'], '0') === 0 && (FinanceDecimal::compare($target['quantity'], '0') !== 0 || $line['method'] !== 'total'))) {
                $this->invalid();
            }
            $contract = $line['source'] === 'contract' ? $contracts->get($line['contract_id'] ?? 0) : null;
            if (($line['source'] === 'contract' && ! $contract) || ($line['source'] !== 'contract' && ! empty($line['contract_id']))) {
                $this->invalid();
            }
            $side = $contract ? $this->query->side($contract) : 'cost';
            if ($side === 'unknown' || ($target['parent_key'] && $side === 'revenue')
                || ($line['source'] === 'included' && ! $target['parent_key'])
                || ($contract && ($contract->currency ?: 'RUB') !== $line['currency'])) {
                $this->invalid();
            }
            $qKey = $line['target_key'].':'.$side;
            $quantities[$qKey] = FinanceDecimal::add($quantities[$qKey] ?? '0', $line['quantity']);
            if (FinanceDecimal::compare($quantities[$qKey], $target['quantity']) > 0) {
                $this->invalid('quantity');
            }
            $amount = null;
            if ($line['source'] === 'included') {
                $amount = '0.00';
            } elseif ($line['method'] === 'unit' && isset($line['unit_price'])) {
                $amount = FinanceDecimal::multiply($line['quantity'], $line['unit_price']);
            } elseif ($line['method'] === 'total' && isset($line['amount'])) {
                $amount = FinanceDecimal::value($line['amount']);
            }
            if (isset($totalKeys[$line['key']])) {
                if ($amount === null || $line['source'] === 'included') {
                    $this->invalid('total');
                }
                $total = FinanceDecimal::add($total, $amount);
                $bases[$line['currency'].':'.$line['price_basis'].':'.$line['source'].':'.($line['contract_id'] ?? 'own')] = true;
            }
            $tax = EstimateFinanceTax::calculate($line, $amount);
            $net = $tax['amount_without_vat'];
            $gross = $tax['amount_with_vat'];
            $foreignKey = $knownKeys->get($line['key']);
            if ($foreignKey && isset($cashLinked[$foreignKey->id]) && ((int) $foreignKey->contract_id !== (int) ($line['contract_id'] ?? 0)
                || $foreignKey->currency !== $line['currency'] || $foreignKey->source !== $line['source']
                || ($foreignKey->resource_id ? 'r:'.$foreignKey->resource_id : 'i:'.$foreignKey->estimate_item_id) !== $line['target_key'])) {
                $this->invalid('cash_linked');
            }
            if (isset($retiredKeys[$line['key']]) || (isset($line['condition_version'])
                && (int) $line['condition_version'] !== (int) ($foreignKey?->condition_version ?? 0))) {
                throw new ConflictHttpException(trans_message('estimate_finance.conflict'));
            }
            $legacyLink = $legacyLinks->get($line['legacy_link_id'] ?? 0);
            if (isset($line['legacy_link_id']) && (! $legacyLink || (int) $legacyLink->estimate_item_id !== $target['item_id']
                || (int) $legacyLink->contract_id !== $contract?->id || $target['resource_id'] !== null)) {
                $this->invalid();
            }
            if ($line['price_basis'] === 'unknown') {
                if ($line['method'] !== 'total' || $line['composition_confirmed'] || isset($line['vat_rate'])) {
                    $this->invalid();
                }
                $net = $legacyLink?->amount_without_vat ?? $foreignKey?->amount_without_vat;
                $original = $legacyLink ?? $foreignKey;
                $originalAmount = $legacyLink?->amount ?? $foreignKey?->legacy_amount;
                if ($original && (FinanceDecimal::compare($line['quantity'], $original->quantity) !== 0
                    || $amount !== ($originalAmount === null ? null : FinanceDecimal::value((string) $originalAmount)))) {
                    $this->invalid();
                }
            }
            if ($foreignKey && ((int) $foreignKey->estimate_id !== (int) $estimate->id
                || ! isset($selectedKeys[$foreignKey->resource_id ? 'r:'.$foreignKey->resource_id : 'i:'.$foreignKey->estimate_item_id]))) {
                $this->invalid();
            }
            $rows[] = [
                'key' => $line['key'], 'organization_id' => $estimate->organization_id, 'estimate_id' => $estimate->id,
                'estimate_item_id' => $target['item_id'], 'resource_id' => $target['resource_id'],
                'contract_id' => $contract?->id, 'side' => $side, 'source' => $line['source'], 'currency' => $line['currency'],
                'quantity' => FinanceDecimal::value($line['quantity'], 8), 'unit_price' => $line['unit_price'] ?? null,
                'amount_without_vat' => $net, 'amount_with_vat' => $gross, 'vat_rate' => $line['vat_rate'] ?? null,
                'vat_mode' => $tax['vat_mode'],
                'legacy_amount' => $line['price_basis'] === 'unknown' ? $amount : null,
                'price_basis' => $line['price_basis'], 'method' => $line['method'],
                'composition_confirmed' => $line['composition_confirmed'], 'notes' => $line['notes'] ?? null,
                'estimate_snapshot' => ($line['adopt_estimate_price'] ?? false) ? array_intersect_key($target, array_flip(['quantity', 'estimate_amount', 'unit_id']))
                    : ($foreignKey?->estimate_snapshot ?? array_intersect_key($target, array_flip(['quantity', 'estimate_amount', 'unit_id']))), 'updated_by' => $actor->id,
            ];
        }
        if (array_diff($data['total_line_keys'] ?? [], array_column($data['lines'], 'key')) !== []) {
            $this->invalid('total');
        }
        if (isset($data['expected_total']) && (count($bases) !== 1 || FinanceDecimal::compare($total, $data['expected_total']) !== 0)) {
            $this->invalid('total');
        }

        $this->acceptedVolume->assertRetained($estimate, $data['target_keys'], $rows);

        return $this->remainder->apply($estimate, $rows);
    }

    private function writeAllocations(array $rows, bool $insert): void
    {
        if ($rows !== []) {
            if ($insert) {
                EstimateFinanceAllocation::query()->insert($rows);
            } else {
                EstimateFinanceAllocation::query()->upsert($rows, ['key'],
                    array_values(array_diff(array_keys($rows[0]), ['key', 'created_at'])));
            }
        }
    }

    private function projectLinks(Estimate $estimate, array $keys): void
    {
        $itemIds = array_map(static fn (string $key): int => (int) substr($key, 2),
            array_values(array_filter($keys, static fn (string $key): bool => str_starts_with($key, 'i:'))));
        $linksByItem = ContractEstimateItem::query()->where('estimate_id', $estimate->id)
            ->whereIn('estimate_item_id', $itemIds)->get()->groupBy('estimate_item_id');
        $allocationsByItem = EstimateFinanceAllocation::query()->where('estimate_id', $estimate->id)
            ->whereIn('estimate_item_id', $itemIds)->whereNull('resource_id')->whereNotNull('contract_id')
            ->get()->groupBy('estimate_item_id');
        $writes = [];
        $timestamp = now()->toDateTimeString();
        foreach ($itemIds as $itemId) {
            $links = $linksByItem->get($itemId, collect());
            $allocations = $allocationsByItem->get($itemId, collect())->groupBy('contract_id');
            foreach ($links as $link) {
                if (! $allocations->has($link->contract_id)) {
                    $writes[] = ['contract_id' => $link->contract_id, 'estimate_item_id' => $itemId,
                        'estimate_id' => $estimate->id, 'finance_managed' => true, 'quantity' => '0',
                        'amount' => '0', 'amount_without_vat' => '0', 'created_at' => $timestamp, 'updated_at' => $timestamp];
                }
            }
            foreach ($allocations as $contractId => $group) {
                $quantity = '0';
                $net = '0';
                $gross = '0';
                $storedAmount = '0';
                foreach ($group as $allocation) {
                    $quantity = FinanceDecimal::add($quantity, $allocation->quantity);
                    $net = $net !== null && $allocation->amount_without_vat !== null ? FinanceDecimal::add($net, $allocation->amount_without_vat) : null;
                    $gross = $gross !== null && $allocation->amount_with_vat !== null ? FinanceDecimal::add($gross, $allocation->amount_with_vat) : null;
                    $value = $allocation->legacy_amount ?? $allocation->amount_with_vat ?? $allocation->amount_without_vat;
                    $storedAmount = $storedAmount !== null && $value !== null ? FinanceDecimal::add($storedAmount, $value) : null;
                }
                $writes[] = ['contract_id' => $contractId, 'estimate_item_id' => $itemId,
                    'estimate_id' => $estimate->id, 'finance_managed' => true, 'quantity' => $quantity,
                    'amount' => $storedAmount, 'amount_without_vat' => $net, 'created_at' => $timestamp, 'updated_at' => $timestamp];
            }
        }
        foreach (array_chunk($writes, 500) as $chunk) {
            ContractEstimateItem::query()->upsert($chunk, ['contract_id', 'estimate_item_id'],
                ['estimate_id', 'finance_managed', 'quantity', 'amount', 'amount_without_vat', 'updated_at']);
        }
        EstimateFinanceAllocation::query()->where('estimate_id', $estimate->id)->whereIn('estimate_item_id', $itemIds)
            ->whereNull('resource_id')->whereNotNull('contract_id')->update([
                'contract_estimate_item_id' => DB::raw('(SELECT id FROM contract_estimate_items WHERE contract_estimate_items.contract_id = estimate_finance_allocations.contract_id AND contract_estimate_items.estimate_item_id = estimate_finance_allocations.estimate_item_id AND contract_estimate_items.estimate_id = estimate_finance_allocations.estimate_id)'),
            ]);
    }

    private function validateResourceChanges(array $data, array $targets, array $after, array $before): void
    {
        $signature = static function (array $rows, array $target): array {
            $result = [];
            foreach ($rows as $row) {
                if ((int) $row['estimate_item_id'] !== $target['item_id'] || $row['resource_id'] !== $target['resource_id'] || $row['source'] === 'included') {
                    continue;
                }
                $result[$row['key']] = [$row['source'], $row['contract_id'], $row['quantity'], $row['amount_without_vat'], $row['amount_with_vat'], $row['currency']];
            }
            ksort($result);

            return $result;
        };
        foreach ($data['target_keys'] as $key) {
            $target = $targets[$key];
            if (! $target['parent_key'] || $signature($after, $target) === $signature($before, $target)) {
                continue;
            }
            if (! ($data['confirm_resource_changes'] ?? false) || ! in_array($target['parent_key'], $data['target_keys'], true)) {
                $this->invalid('composition');
            }
            $parent = $targets[$target['parent_key']];
            foreach ($after as $row) {
                if ((int) $row['estimate_item_id'] === $parent['item_id'] && $row['resource_id'] === $parent['resource_id']
                    && $row['side'] === 'cost' && $row['source'] !== 'included' && ! $row['composition_confirmed']) {
                    $this->invalid('composition');
                }
            }
        }
    }

    private function mapResources(Estimate $estimate, array $data): array
    {
        $changes = [];
        if (empty($data['resource_mappings'])) {
            return $changes;
        }
        if (! ($data['confirm_resource_changes'] ?? false)) {
            $this->invalid('composition');
        }
        $resources = \App\Models\EstimateItemResource::query()->whereHas('item', fn ($query) => $query->where('estimate_id', $estimate->id))
            ->whereIn('id', array_column($data['resource_mappings'], 'resource_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($data['resource_mappings'] as $mapping) {
            $resource = $resources->get($mapping['resource_id']);
            if (! $resource || ! in_array('r:'.$resource->id, $data['target_keys'], true)
                || EstimateFinanceAllocation::query()->where('resource_id', $resource->id)->exists()) {
                $this->invalid('representation');
            }
            if ($mapping['item_id'] !== null) {
                $child = \App\Models\EstimateItem::query()->where('estimate_id', $estimate->id)
                    ->where('parent_work_id', $resource->estimate_item_id)->whereKey($mapping['item_id'])->first();
                if (! $child || $child->measurement_unit_id !== $resource->measurement_unit_id
                    || FinanceDecimal::compare((string) ($child->quantity_total ?? $child->quantity), (string) $resource->total_quantity) !== 0) {
                    $this->invalid('representation');
                }
            }
            $before = $resource->only(['finance_representation', 'represented_by_item_id']);
            $resource->update(['finance_representation' => $mapping['item_id'] ? 'child' : 'independent', 'represented_by_item_id' => $mapping['item_id']]);
            $changes[] = ['resource_id' => $resource->id, 'before' => $before, 'after' => $resource->only(['finance_representation', 'represented_by_item_id'])];
        }

        return $changes;
    }

    private function prepareResources(Estimate $estimate, array $data): array
    {
        $created = [];
        foreach ($data['prepare_resource_items'] ?? [] as $id) {
            if (! in_array('i:'.$id, $data['target_keys'], true)) {
                $this->invalid();
            }
            $item = $estimate->items()->whereKey($id)->with('resources')->firstOrFail();
            if ($item->resources->isNotEmpty()) {
                $this->invalid('conflict');
            }
            foreach ($this->query->pendingResources($item) as $definition) {
                foreach (['quantity', 'amount', 'unit_price', 'quantity_per_unit'] as $field) {
                    if (! is_numeric($definition[$field]) || FinanceDecimal::compare($definition[$field], '0') < 0) {
                        $this->invalid();
                    }
                }
                $resource = \App\Models\EstimateItemResource::query()->create([
                    'estimate_item_id' => $id, 'resource_type' => match ($definition['type']) {
                        'material', 'labor', 'equipment' => $definition['type'], 'machinery' => 'equipment', default => 'other',
                    },
                    'name' => $definition['name'], 'finance_unit_label' => $definition['unit'],
                    'finance_source_hash' => hash('sha256', json_encode($this->query->pendingResources($item), JSON_THROW_ON_ERROR)),
                    'total_quantity' => $definition['quantity'], 'quantity_per_unit' => $definition['quantity_per_unit'],
                    'total_amount' => $definition['amount'], 'unit_price' => $definition['unit_price'],
                ]);
                $created[] = $resource->id;
            }
        }

        return $created;
    }

    private function invalid(string $key = 'invalid'): never
    {
        throw ValidationException::withMessages(['lines' => trans_message('estimate_finance.'.$key)]);
    }
}
