<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\BusinessModules\Features\BudgetEstimates\Http\Requests\SaveEstimateFinanceRequest;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCacheService;
use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\Estimate;
use App\Models\EstimateFinanceAllocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class EstimateFinanceService
{
    public function __construct(
        private readonly EstimateFinanceAccess $access,
        private readonly EstimateFinanceQuery $query,
        private readonly EstimateFinanceCalculator $calculator,
        private readonly EstimateCacheService $cache,
    ) {}

    public function report(User $actor, int $projectId, int $estimateId, string $basis = 'with_vat'): array
    {
        $estimate = $this->access->estimate($actor, $projectId, $estimateId);

        return DB::transaction(function () use ($actor, $estimate, $basis): array {
            $locked = Estimate::query()->whereKey($estimate->id)->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $estimate->project_id)->sharedLock()->firstOrFail();

            return $this->reportEstimate($actor, $locked, $basis);
        }, 3);
    }

    public function projectReport(User $actor, int $projectId, string $basis, bool $includeDetails = false): array
    {
        $this->access->project($actor, $projectId);

        return DB::transaction(function () use ($actor, $projectId, $basis, $includeDetails): array {
            $reports = [];
            foreach (Estimate::query()->where('organization_id', $actor->current_organization_id)->where('project_id', $projectId)->orderBy('id')->sharedLock()->cursor() as $estimate) {
                $report = $this->reportEstimate($actor, $estimate, $basis);
                if (! $includeDetails) {
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

            return ['basis' => $basis, 'estimates' => $reports, 'totals' => array_values($totals)];
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

    private function reportEstimate(User $actor, Estimate $estimate, string $basis): array
    {
        if (! in_array($basis, ['with_vat', 'without_vat'], true)) {
            $this->invalid();
        }
        $targets = $this->query->targets($estimate);
        $allocations = $this->query->allocations($estimate);
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

        return $calculation + [
            'estimate_id' => (int) $estimate->id, 'name' => $estimate->name, 'number' => $estimate->number,
            'revision' => (int) $estimate->finance_revision, 'basis' => $basis,
            'limit' => $basis === 'with_vat' ? $estimate->total_amount_with_vat : $estimate->total_amount,
            'sections' => $sections,
            'contracts' => $contracts, 'can_edit' => $this->access->can($actor, (int) $estimate->project_id, true),
        ];
    }

    public function preview(User $actor, int $projectId, int $estimateId, array $input): array
    {
        $estimate = $this->access->estimate($actor, $projectId, $estimateId, true);
        $data = Validator::make($input, SaveEstimateFinanceRequest::inputRules())->validate();
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
        $estimate = $this->access->estimate($actor, $projectId, $estimateId, true);
        $data = Validator::make($input, SaveEstimateFinanceRequest::inputRules())->validate();
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
            foreach ($existing as $row) {
                $key = $row->resource_id ? 'r:'.$row->resource_id : 'i:'.$row->estimate_item_id;
                if (in_array($key, $data['target_keys'], true)) {
                    $before[] = $row->toArray();
                    $row->delete();
                }
            }
            foreach ($normalized as $row) {
                EstimateFinanceAllocation::query()->create($row);
            }
            $this->projectLinks($estimate, $data['target_keys']);
            $revision = (int) $estimate->fresh()->finance_revision + 1;
            DB::table('estimates')->where('id', $estimate->id)->update(['finance_revision' => $revision]);
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
        foreach ($data['target_keys'] as $key) {
            if (! isset($targets[$key]) || $targets[$key]['excluded']) {
                $this->invalid();
            }
        }
        $contracts = Contract::query()->where('organization_id', $estimate->organization_id)->where('project_id', $estimate->project_id)
            ->whereIn('id', array_filter(array_column($data['lines'], 'contract_id')))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $rows = [];
        $quantities = [];
        $knownKeys = EstimateFinanceAllocation::query()->whereIn('key', array_column($data['lines'], 'key'))->get()->keyBy('key');
        $legacyLinks = ContractEstimateItem::query()->where('estimate_id', $estimate->id)->where('finance_managed', false)
            ->whereIn('id', array_column($data['lines'], 'legacy_link_id'))->get()->keyBy('id');
        $total = '0.00';
        $bases = [];
        foreach ($data['lines'] as $line) {
            $target = $targets[$line['target_key']] ?? null;
            if ($target && (($target['representation_needs_review'] ?? false) || ($target['represented_by_item_id'] ?? null))) {
                $this->invalid('representation');
            }
            if (! $target || ! in_array($line['target_key'], $data['target_keys'], true)
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
            if (in_array($line['key'], $data['total_line_keys'] ?? [], true)) {
                if ($amount === null || $line['source'] === 'included') {
                    $this->invalid('total');
                }
                $total = FinanceDecimal::add($total, $amount);
                $bases[$line['currency'].':'.$line['price_basis'].':'.$line['source'].':'.($line['contract_id'] ?? 'own')] = true;
            }
            $net = $line['price_basis'] === 'without_vat' ? $amount : null;
            $gross = $line['price_basis'] === 'with_vat' ? $amount : null;
            if ($amount !== null && isset($line['vat_rate']) && $line['price_basis'] !== 'unknown') {
                $factor = FinanceDecimal::add('1', FinanceDecimal::divide($line['vat_rate'], '100', 8));
                $net ??= FinanceDecimal::divide($amount, $factor);
                $gross ??= FinanceDecimal::multiply($amount, $factor);
            }
            $foreignKey = $knownKeys->get($line['key']);
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
                || ! in_array($foreignKey->resource_id ? 'r:'.$foreignKey->resource_id : 'i:'.$foreignKey->estimate_item_id, $data['target_keys'], true))) {
                $this->invalid();
            }
            $rows[] = [
                'key' => $line['key'], 'organization_id' => $estimate->organization_id, 'estimate_id' => $estimate->id,
                'estimate_item_id' => $target['item_id'], 'resource_id' => $target['resource_id'],
                'contract_id' => $contract?->id, 'side' => $side, 'source' => $line['source'], 'currency' => $line['currency'],
                'quantity' => FinanceDecimal::value($line['quantity'], 8), 'unit_price' => $line['unit_price'] ?? null,
                'amount_without_vat' => $net, 'amount_with_vat' => $gross, 'vat_rate' => $line['vat_rate'] ?? null,
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

        return $rows;
    }

    private function projectLinks(Estimate $estimate, array $keys): void
    {
        foreach ($keys as $key) {
            if (! str_starts_with($key, 'i:')) {
                continue;
            }
            $itemId = (int) substr($key, 2);
            $links = ContractEstimateItem::query()->where('estimate_id', $estimate->id)->where('estimate_item_id', $itemId)->get();
            $allocations = EstimateFinanceAllocation::query()->where('estimate_id', $estimate->id)
                ->where('estimate_item_id', $itemId)->whereNull('resource_id')->whereNotNull('contract_id')->get()->groupBy('contract_id');
            foreach ($links as $link) {
                $link->forceFill(['finance_managed' => true] + (! $allocations->has($link->contract_id)
                    ? ['quantity' => '0', 'amount' => '0', 'amount_without_vat' => '0'] : []))->save();
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
                $link = ContractEstimateItem::query()->firstOrNew(['contract_id' => $contractId, 'estimate_item_id' => $itemId]);
                $link->forceFill(['estimate_id' => $estimate->id, 'finance_managed' => true, 'quantity' => $quantity,
                    'amount' => $storedAmount, 'amount_without_vat' => $net])->save();
                EstimateFinanceAllocation::query()->whereIn('id', $group->pluck('id'))->update(['contract_estimate_item_id' => $link->id]);
            }
        }
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
