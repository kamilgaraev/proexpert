<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\ReadOnly;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class GetContractSnapshotTool extends AbstractReadOnlyTool
{
    public function getName(): string
    {
        return 'get_contract_snapshot';
    }

    public function getDescription(): string
    {
        return 'Возвращает read-only сводку по договорам: карточки, контрагенты, проекты, суммы, акты и платежные документы.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'contract_id' => ['type' => 'integer', 'description' => 'ID договора'],
                'project_id' => ['type' => 'integer', 'description' => 'ID проекта'],
                'number' => ['type' => 'string', 'description' => 'Номер договора или его часть'],
                'contractor_name' => ['type' => 'string', 'description' => 'Название подрядчика или поставщика'],
                'status' => ['type' => 'string', 'description' => 'Статус договора'],
                'date_from' => ['type' => 'string', 'description' => 'Дата начала периода YYYY-MM-DD'],
                'date_to' => ['type' => 'string', 'description' => 'Дата конца периода YYYY-MM-DD'],
                'limit' => ['type' => 'integer', 'description' => 'Максимум договоров, от 1 до 30', 'default' => 10],
            ],
        ];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        unset($user);

        if (!$this->hasTable('contracts')) {
            return $this->tableUnavailable('contracts', 'contracts');
        }

        $query = $this->withoutDeleted($this->orgTable('contracts', $organization), 'contracts')
            ->leftJoin('contractors', 'contracts.contractor_id', '=', 'contractors.id')
            ->leftJoin('projects', 'contracts.project_id', '=', 'projects.id');

        $contractId = $this->intArg($arguments, 'contract_id');
        $projectId = $this->intArg($arguments, 'project_id');
        $number = $this->stringArg($arguments, 'number');
        $contractorName = $this->stringArg($arguments, 'contractor_name');
        $status = $this->stringArg($arguments, 'status');

        if ($contractId !== null) {
            $query->where('contracts.id', $contractId);
        }

        if ($projectId !== null) {
            $query->where('contracts.project_id', $projectId);
        }

        if ($number !== null) {
            $query->where('contracts.number', 'ilike', "%{$number}%");
        }

        if ($contractorName !== null) {
            $query->where('contractors.name', 'ilike', "%{$contractorName}%");
        }

        if ($status !== null) {
            $query->where('contracts.status', $status);
        }

        $this->applyDateRange($query, 'contracts.date', $arguments);

        $contracts = $query
            ->select([
                'contracts.id',
                'contracts.number',
                'contracts.date',
                'contracts.subject',
                'contracts.status',
                'contracts.total_amount',
                'contracts.planned_advance_amount',
                'contracts.actual_advance_amount',
                'contracts.start_date',
                'contracts.end_date',
                'contractors.id as contractor_id',
                'contractors.name as contractor_name',
                'projects.id as project_id',
                'projects.name as project_name',
            ])
            ->orderByDesc('contracts.date')
            ->limit($this->limit($arguments))
            ->get();

        $items = $contracts->map(fn (object $contract): array => $this->contractSnapshot($contract, $organization))->all();

        return [
            'status' => 'success',
            'domain' => 'contracts',
            'summary' => [
                'contracts_count' => count($items),
                'total_amount' => round(array_sum(array_map(
                    static fn (array $item): float => (float) ($item['contract']['total_amount'] ?? 0),
                    $items
                )), 2),
            ],
            'contracts' => $items,
        ];
    }

    private function contractSnapshot(object $contract, Organization $organization): array
    {
        $contractId = (int) $contract->id;

        return [
            'contract' => [
                'id' => $contractId,
                'number' => $contract->number,
                'date' => $contract->date,
                'subject' => $contract->subject,
                'status' => $contract->status,
                'total_amount' => $contract->total_amount !== null ? (float) $contract->total_amount : null,
                'planned_advance' => $contract->planned_advance_amount !== null ? (float) $contract->planned_advance_amount : null,
                'actual_advance' => $contract->actual_advance_amount !== null ? (float) $contract->actual_advance_amount : null,
                'start_date' => $contract->start_date,
                'end_date' => $contract->end_date,
            ],
            'contractor' => [
                'id' => $contract->contractor_id,
                'name' => $contract->contractor_name,
            ],
            'project' => [
                'id' => $contract->project_id,
                'name' => $contract->project_name,
            ],
            'financial' => $this->financialSummary($organization, $contractId),
        ];
    }

    private function financialSummary(Organization $organization, int $contractId): array
    {
        $actsAmount = 0.0;
        $actsCount = 0;

        if ($this->hasTable('acts')) {
            $acts = $this->withoutDeleted(DB::table('acts'), 'acts')
                ->where('acts.contract_id', $contractId);
            $actsAmount = round((float) (clone $acts)->sum('total_amount'), 2);
            $actsCount = (clone $acts)->count();
        }

        $paymentsAmount = 0.0;
        $paidAmount = 0.0;
        $paymentsCount = 0;

        if ($this->hasTable('payment_documents')) {
            $payments = $this->withoutDeleted($this->orgTable('payment_documents', $organization), 'payment_documents')
                ->where('payment_documents.invoiceable_type', 'App\\Models\\Contract')
                ->where('payment_documents.invoiceable_id', $contractId);
            $paymentsAmount = round((float) (clone $payments)->sum('amount'), 2);
            $paidAmount = round((float) (clone $payments)->where('status', 'paid')->sum('amount'), 2);
            $paymentsCount = (clone $payments)->count();
        }

        return [
            'acts_count' => $actsCount,
            'acts_amount' => $actsAmount,
            'payment_documents_count' => $paymentsCount,
            'payment_documents_amount' => $paymentsAmount,
            'paid_amount' => $paidAmount,
        ];
    }
}
