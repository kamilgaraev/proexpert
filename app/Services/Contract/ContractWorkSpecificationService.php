<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceAccess;
use App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceContractBudget;
use App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceQuery;
use App\BusinessModules\Features\BudgetEstimates\Services\Finance\FinanceDecimal;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\Estimate;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ContractWorkSpecificationService
{
    public function __construct(
        private readonly EstimateFinanceAccess $access,
        private readonly EstimateFinanceQuery $query,
        private readonly AuthorizationService $authorization,
        private readonly ContractDocumentExporter $exporter,
    ) {}

    public function current(User $actor, int $projectId, int $contractId): array
    {
        $this->access->project($actor, $projectId);
        $context = ['context_type' => 'project', 'project_id' => $projectId, 'organization_id' => (int) $actor->current_organization_id];
        if (! $this->authorization->can($actor, 'contracts.view', $context)
            || ! $this->authorization->can($actor, 'specifications.view', $context)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $projectId, $contractId): array {
            $contract = Contract::query()->where('organization_id', $actor->current_organization_id)->whereKey($contractId)
                ->where(function ($query) use ($projectId): void {
                    $query->where('project_id', $projectId)->orWhereHas('projects', fn ($projects) => $projects->where('projects.id', $projectId));
                })->sharedLock()->firstOrFail();
            $estimateIds = DB::table('estimate_finance_allocations')->where('organization_id', $actor->current_organization_id)
                ->where('contract_id', $contractId)->pluck('estimate_id')->all();
            $estimateIds = array_unique(array_merge($estimateIds, DB::table('contract_estimate_items as links')
                ->join('estimates as estimates', 'estimates.id', '=', 'links.estimate_id')
                ->where('estimates.organization_id', $actor->current_organization_id)->where('links.contract_id', $contractId)
                ->where('links.finance_managed', false)->pluck('links.estimate_id')->all()));
            $rows = [];
            $estimates = [];
            $checkedProjects = [$projectId => true];
            $net = '0.00';
            $gross = '0.00';
            $netKnown = true;
            $grossKnown = true;
            foreach (Estimate::query()->where('organization_id', $actor->current_organization_id)->whereIn('id', $estimateIds)->orderBy('id')->get() as $estimate) {
                if (! isset($checkedProjects[$estimate->project_id])) {
                    $this->access->project($actor, (int) $estimate->project_id);
                    $checkedProjects[$estimate->project_id] = true;
                }
                $targets = $this->query->targets($estimate);
                $estimates[] = ['id' => (int) $estimate->id, 'project_id' => (int) $estimate->project_id,
                    'number' => $estimate->number, 'name' => $estimate->name, 'revision' => (int) $estimate->finance_revision];
                foreach ($this->query->allocations($estimate) as $line) {
                    if ((int) $line['contract_id'] !== $contractId
                        || (isset($line['organization_id']) && (int) $line['organization_id'] !== (int) $actor->current_organization_id)) {
                        continue;
                    }
                    $target = $targets[$line['target_key']] ?? null;
                    if ($target === null) {
                        $target = ['name' => trans_message('estimate_finance.specification_missing_position'), 'position_number' => '',
                            'unit' => null, 'resource_id' => $line['resource_id'] ?? null, 'quantity' => $line['quantity']];
                    }
                    $quantity = $line['quantity'];
                    if (! $target['resource_id'] && isset($target['base_quantity']) && FinanceDecimal::compare($target['quantity'], '0') > 0) {
                        $quantity = FinanceDecimal::divide(FinanceDecimal::multiply($quantity, $target['base_quantity'], 16), $target['quantity'], 8);
                    }
                    $lineNet = $line['amount_without_vat'] ?? null;
                    $lineGross = $line['amount_with_vat'] ?? null;
                    $sameCurrency = $line['currency'] === ($contract->currency ?: 'RUB');
                    $netKnown = $netKnown && $lineNet !== null && $sameCurrency;
                    $grossKnown = $grossKnown && $lineGross !== null && $sameCurrency;
                    if ($sameCurrency) {
                        $net = FinanceDecimal::add($net, $lineNet ?? '0');
                        $gross = FinanceDecimal::add($gross, $lineGross ?? '0');
                    }
                    $rows[] = [
                        'key' => $line['key'], 'estimate_id' => (int) $estimate->id, 'estimate_number' => $estimate->number,
                        'position_number' => $target['position_number'], 'name' => $target['name'], 'unit' => $target['unit'],
                        'quantity' => (string) $quantity,
                        'unit_price_without_vat' => $lineNet !== null && FinanceDecimal::compare($quantity, '0') > 0 ? FinanceDecimal::divide($lineNet, $quantity, 4) : null,
                        'amount_without_vat' => $lineNet, 'amount_with_vat' => $lineGross,
                        'vat_amount' => $lineNet !== null && $lineGross !== null ? FinanceDecimal::subtract($lineGross, $lineNet) : null,
                        'vat_mode' => $line['vat_mode'], 'vat_rate' => $line['vat_rate'], 'currency' => $line['currency'],
                        'composition_confirmed' => $line['composition_confirmed'],
                    ];
                }
            }

            return ['contract' => ['id' => (int) $contract->id, 'number' => $contract->number,
                'date' => $contract->date?->format('d.m.Y'), 'currency' => $contract->currency ?: 'RUB',
                'amount' => $contract->is_fixed_amount ? (string) $contract->total_amount : null,
                'binding_budget' => app(EstimateFinanceContractBudget::class)->summaries([$contract])[$contract->id]],
                'rows' => $rows, 'estimates' => $estimates,
                'totals' => ['amount_without_vat' => $netKnown ? FinanceDecimal::value($net) : null,
                    'amount_with_vat' => $grossKnown ? FinanceDecimal::value($gross) : null,
                    'vat_amount' => $netKnown && $grossKnown ? FinanceDecimal::value(FinanceDecimal::subtract($gross, $net)) : null]];
        }, 3);
    }

    public function export(User $actor, int $projectId, int $contractId, string $format): string
    {
        $data = $this->current($actor, $projectId, $contractId);

        return $this->exporter->render(view('contracts.work-specification', ['specification' => $data])->render(), $format);
    }
}
