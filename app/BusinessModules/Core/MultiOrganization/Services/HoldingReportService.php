<?php

namespace App\BusinessModules\Core\MultiOrganization\Services;

use App\BusinessModules\Core\MultiOrganization\Requests\HoldingReportRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Contract;
use App\Models\Contractor;
use App\Services\Export\CsvExporterService;
use App\Services\Export\ExcelExporterService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HoldingReportService
{
    public function __construct(
        protected FilterScopeManager $filterManager,
        protected ContextAwareOrganizationScope $scope,
        protected CsvExporterService $csvExporter,
        protected ExcelExporterService $excelExporter
    ) {}

    public function getProjectsSummaryReport(HoldingReportRequest $request, int $holdingId): array|StreamedResponse
    {
        $holding = $this->validateHoldingAccess($holdingId);
        $availableOrgIds = $this->scope->getOrganizationScope($holdingId);
        $filters = $this->extractFilters($request);

        $selectedOrgIds = !empty($filters['organization_ids']) 
            ? array_intersect($filters['organization_ids'], $availableOrgIds)
            : $availableOrgIds;

        Log::info('Holding projects report requested', [
            'holding_id' => $holdingId,
            'organizations' => count($selectedOrgIds),
            'filters' => $filters
        ]);

        $query = Project::query()
            ->whereIn('organization_id', $selectedOrgIds)
            ->with('organization:id,name');

        $this->applyProjectFilters($query, $filters);

        $totalQuery = clone $query;
        $summary = $this->calculateProjectsSummary($totalQuery, $selectedOrgIds);

        $byOrganization = $this->getProjectsByOrganization($selectedOrgIds, $filters);

        $topProjects = $this->getTopProjects($query, $filters);

        $result = [
            'title' => 'Сводный отчет по проектам холдинга',
            'holding' => [
                'id' => $holding->id,
                'name' => $holding->name,
            ],
            'period' => [
                'from' => $filters['date_from'],
                'to' => $filters['date_to'],
            ],
            'filters' => $filters,
            'summary' => $summary,
            'by_organization' => $byOrganization,
            'top_projects' => $topProjects,
            'generated_at' => now()->toISOString(),
        ];

        if ($request->input('export_format')) {
            return $this->exportProjectsReport($result, $request->input('export_format'));
        }

        return $result;
    }

    public function getContractsSummaryReport(HoldingReportRequest $request, int $holdingId): array|StreamedResponse
    {
        $holding = $this->validateHoldingAccess($holdingId);
        $availableOrgIds = $this->scope->getOrganizationScope($holdingId);
        $filters = $this->extractFilters($request);

        $selectedOrgIds = !empty($filters['organization_ids']) 
            ? array_intersect($filters['organization_ids'], $availableOrgIds)
            : $availableOrgIds;

        Log::info('Holding contracts report requested', [
            'holding_id' => $holdingId,
            'organizations' => count($selectedOrgIds),
            'filters' => $filters
        ]);

        $query = Contract::query()
            ->whereIn('organization_id', $selectedOrgIds)
            ->with(['organization:id,name', 'contractor:id,name,contact_person', 'project:id,name']);

        $this->applyContractFilters($query, $filters);

        $totalQuery = clone $query;
        $summary = $this->calculateContractsSummary($totalQuery);

        $byOrganization = $this->getContractsByOrganization($selectedOrgIds, $filters);

        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 50);
        $byContractor = $this->getContractsByContractor($selectedOrgIds, $filters, $page, $perPage);

        $result = [
            'title' => 'Сводный отчет по контрактам холдинга',
            'holding' => [
                'id' => $holding->id,
                'name' => $holding->name,
            ],
            'period' => [
                'from' => $filters['date_from'],
                'to' => $filters['date_to'],
            ],
            'filters' => $filters,
            'summary' => $summary,
            'by_organization' => $byOrganization,
            'by_contractor' => $byContractor,
            'generated_at' => now()->toISOString(),
        ];

        if ($request->input('export_format')) {
            return $this->exportContractsReport($result, $request->input('export_format'));
        }

        return $result;
    }

    protected function validateHoldingAccess(int $holdingId): Organization
    {
        $holding = Organization::findOrFail($holdingId);

        if (!$holding->is_holding) {
            abort(403, 'Access restricted to holding organizations');
        }

        return $holding;
    }

    protected function extractFilters(HoldingReportRequest $request): array
    {
        return [
            'organization_ids' => $request->input('organization_ids', []),
            'status' => $request->input('status'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'include_archived' => $request->input('include_archived', false),
            'contractor_ids' => $request->input('contractor_ids', []),
            'project_id' => $request->input('project_id'),
            'min_amount' => $request->input('min_amount'),
            'max_amount' => $request->input('max_amount'),
            'min_budget' => $request->input('min_budget'),
            'max_budget' => $request->input('max_budget'),
            'customer' => $request->input('customer'),
            'work_type_category' => $request->input('work_type_category'),
            'include_child_contracts' => $request->input('include_child_contracts', false),
        ];
    }

    protected function applyProjectFilters($query, array $filters): void
    {
        if ($filters['status']) {
            $query->where('status', $filters['status']);
        }

        if (!$filters['include_archived']) {
            $query->where('is_archived', false);
        }

        if ($filters['date_from']) {
            $query->where('start_date', '>=', Carbon::parse($filters['date_from']));
        }

        if ($filters['date_to']) {
            $query->where('start_date', '<=', Carbon::parse($filters['date_to']));
        }

        if ($filters['min_budget']) {
            $query->where('budget_amount', '>=', $filters['min_budget']);
        }

        if ($filters['max_budget']) {
            $query->where('budget_amount', '<=', $filters['max_budget']);
        }

        if ($filters['customer']) {
            $query->where('customer', 'like', '%' . $filters['customer'] . '%');
        }
    }

    protected function applyContractFilters($query, array $filters): void
    {
        if ($filters['status']) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['contractor_ids'])) {
            $query->whereIn('contractor_id', $filters['contractor_ids']);
        }

        if ($filters['project_id']) {
            $query->where('project_id', $filters['project_id']);
        }

        if ($filters['date_from']) {
            $query->where('date', '>=', Carbon::parse($filters['date_from']));
        }

        if ($filters['date_to']) {
            $query->where('date', '<=', Carbon::parse($filters['date_to']));
        }

        if ($filters['min_amount']) {
            $query->where('total_amount', '>=', $filters['min_amount']);
        }

        if ($filters['max_amount']) {
            $query->where('total_amount', '<=', $filters['max_amount']);
        }

        if ($filters['work_type_category']) {
            $query->where('work_type_category', $filters['work_type_category']);
        }

        if (!$filters['include_child_contracts']) {
            $query->whereNull('parent_contract_id');
        }
    }

    protected function calculateProjectsSummary($query, array $orgIds): array
    {
        $projects = $query->get();

        $contractsTotal = DB::table('contracts')
            ->whereIn('organization_id', $orgIds)
            ->whereIn('project_id', $projects->pluck('id'))
            ->sum('total_amount');

        $completedWorksTotal = DB::table('completed_works')
            ->whereIn('project_id', $projects->pluck('id'))
            ->where('status', 'confirmed')
            ->sum('total_amount');

        $materialsTotal = DB::table('material_receipts')
            ->whereIn('project_id', $projects->pluck('id'))
            ->sum('total_amount');

        return [
            'total_projects' => $projects->count(),
            'total_budget' => round($projects->sum('budget_amount'), 2),
            'total_contracts_amount' => round($contractsTotal, 2),
            'total_completed_works' => round($completedWorksTotal, 2),
            'total_materials_cost' => round($materialsTotal, 2),
            'by_status' => $projects->groupBy('status')->map(function ($group, $status) {
                return [
                    'status' => $status,
                    'count' => $group->count(),
                    'total_budget' => round($group->sum('budget_amount'), 2),
                ];
            })->values(),
        ];
    }

    protected function calculateContractsSummary($query): array
    {
        $contracts = $query->get();
        $contractIds = $contracts->pluck('id');

        $totalPaid = DB::table('contract_payments')
            ->whereIn('contract_id', $contractIds)
            ->sum('amount');

        $totalActs = DB::table('contract_performance_acts')
            ->whereIn('contract_id', $contractIds)
            ->where('is_approved', true)
            ->sum('amount');

        $totalAmount = $contracts->sum('total_amount');
        $totalGp = $contracts->sum(function ($contract) {
            return $contract->gp_amount ?? 0;
        });

        return [
            'total_contracts' => $contracts->count(),
            'total_amount' => round($totalAmount, 2),
            'total_gp_amount' => round($totalGp, 2),
            'total_paid' => round($totalPaid, 2),
            'total_acts_approved' => round($totalActs, 2),
            'remaining_amount' => round($totalAmount - $totalPaid, 2),
            'completion_percentage' => $totalAmount > 0 ? round(($totalActs / $totalAmount) * 100, 2) : 0,
            'payment_percentage' => $totalAmount > 0 ? round(($totalPaid / $totalAmount) * 100, 2) : 0,
            'total_planned_advance' => round($contracts->sum('planned_advance_amount'), 2),
            'total_actual_advance' => round($contracts->sum('actual_advance_amount'), 2),
            'by_status' => $contracts->groupBy('status')->map(function ($group, $status) {
                return [
                    'status' => $status,
                    'count' => $group->count(),
                    'total_amount' => round($group->sum('total_amount'), 2),
                ];
            })->values(),
        ];
    }

    protected function getProjectsByOrganization(array $orgIds, array $filters): array
    {
        $organizations = Organization::whereIn('id', $orgIds)->get();
        $result = [];

        foreach ($organizations as $org) {
            $query = Project::where('organization_id', $org->id);
            $this->applyProjectFilters($query, $filters);
            
            $projects = $query->get();
            $projectIds = $projects->pluck('id');

            $contractsAmount = DB::table('contracts')
                ->where('organization_id', $org->id)
                ->whereIn('project_id', $projectIds)
                ->sum('total_amount');

            $completedWorks = DB::table('completed_works')
                ->whereIn('project_id', $projectIds)
                ->where('status', 'confirmed')
                ->sum('total_amount');

            $result[] = [
                'organization_id' => $org->id,
                'organization_name' => $org->name,
                'projects_count' => $projects->count(),
                'total_budget' => round($projects->sum('budget_amount'), 2),
                'contracts_amount' => round($contractsAmount, 2),
                'completed_works' => round($completedWorks, 2),
                'completion_percentage' => $contractsAmount > 0 
                    ? round(($completedWorks / $contractsAmount) * 100, 2) 
                    : 0,
                'by_status' => $projects->groupBy('status')->map(fn($g) => $g->count())->toArray(),
            ];
        }

        return $result;
    }

    protected function getContractsByOrganization(array $orgIds, array $filters): array
    {
        $organizations = Organization::whereIn('id', $orgIds)->get();
        $result = [];

        foreach ($organizations as $org) {
            $query = Contract::where('organization_id', $org->id);
            $this->applyContractFilters($query, $filters);
            
            $contracts = $query->get();
            $contractIds = $contracts->pluck('id');

            $totalPaid = DB::table('contract_payments')
                ->whereIn('contract_id', $contractIds)
                ->sum('amount');

            $totalActs = DB::table('contract_performance_acts')
                ->whereIn('contract_id', $contractIds)
                ->where('is_approved', true)
                ->sum('amount');

            $totalAmount = $contracts->sum('total_amount');

            $result[] = [
                'organization_id' => $org->id,
                'organization_name' => $org->name,
                'contracts_count' => $contracts->count(),
                'total_amount' => round($totalAmount, 2),
                'total_gp_amount' => round($contracts->sum(fn($c) => $c->gp_amount ?? 0), 2),
                'total_paid' => round($totalPaid, 2),
                'total_acts_approved' => round($totalActs, 2),
                'remaining_amount' => round($totalAmount - $totalPaid, 2),
                'completion_percentage' => $totalAmount > 0 ? round(($totalActs / $totalAmount) * 100, 2) : 0,
                'payment_percentage' => $totalAmount > 0 ? round(($totalPaid / $totalAmount) * 100, 2) : 0,
                'by_status' => $contracts->groupBy('status')->map(fn($g) => $g->count())->toArray(),
            ];
        }

        return $result;
    }

    protected function getContractsByContractor(array $orgIds, array $filters, int $page, int $perPage): array
    {
        $query = DB::table('contractors')
            ->select([
                'contractors.id',
                'contractors.name',
                'contractors.contact_person',
                'contractors.phone',
                'contractors.email',
                'contractors.contractor_type',
                DB::raw('COUNT(DISTINCT contracts.id) as contracts_count'),
                DB::raw('COALESCE(SUM(contracts.total_amount), 0) as total_amount'),
                DB::raw('GROUP_CONCAT(DISTINCT orgs.name SEPARATOR ", ") as organizations'),
            ])
            ->join('contracts', 'contractors.id', '=', 'contracts.contractor_id')
            ->join('organizations as orgs', 'contracts.organization_id', '=', 'orgs.id')
            ->whereIn('contracts.organization_id', $orgIds)
            ->groupBy([
                'contractors.id',
                'contractors.name',
                'contractors.contact_person',
                'contractors.phone',
                'contractors.email',
                'contractors.contractor_type'
            ]);

        if (!empty($filters['contractor_ids'])) {
            $query->whereIn('contractors.id', $filters['contractor_ids']);
        }

        if ($filters['status']) {
            $query->where('contracts.status', $filters['status']);
        }

        if ($filters['date_from']) {
            $query->where('contracts.date', '>=', Carbon::parse($filters['date_from']));
        }

        if ($filters['date_to']) {
            $query->where('contracts.date', '<=', Carbon::parse($filters['date_to']));
        }

        $total = DB::table(DB::raw("({$query->toSql()}) as sub"))
            ->mergeBindings($query)
            ->count();

        $contractors = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $contractorsData = [];
        foreach ($contractors as $contractor) {
            $contractIds = DB::table('contracts')
                ->where('contractor_id', $contractor->id)
                ->whereIn('organization_id', $orgIds)
                ->pluck('id');

            $totalPaid = DB::table('contract_payments')
                ->whereIn('contract_id', $contractIds)
                ->sum('amount');

            $totalActs = DB::table('contract_performance_acts')
                ->whereIn('contract_id', $contractIds)
                ->where('is_approved', true)
                ->sum('amount');

            $contractorsData[] = [
                'contractor_id' => $contractor->id,
                'contractor_name' => $contractor->name,
                'contact_person' => $contractor->contact_person,
                'phone' => $contractor->phone,
                'email' => $contractor->email,
                'contractor_type' => $contractor->contractor_type,
                'contracts_count' => $contractor->contracts_count,
                'total_amount' => round($contractor->total_amount, 2),
                'total_paid' => round($totalPaid, 2),
                'total_acts_approved' => round($totalActs, 2),
                'remaining_amount' => round($contractor->total_amount - $totalPaid, 2),
                'completion_percentage' => $contractor->total_amount > 0 
                    ? round(($totalActs / $contractor->total_amount) * 100, 2) 
                    : 0,
                'payment_percentage' => $contractor->total_amount > 0 
                    ? round(($totalPaid / $contractor->total_amount) * 100, 2) 
                    : 0,
                'organizations' => $contractor->organizations,
            ];
        }

        return [
            'data' => $contractorsData,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => ceil($total / $perPage),
            ],
        ];
    }

    protected function getTopProjects($query, array $filters): array
    {
        $topByBudget = (clone $query)
            ->orderByDesc('budget_amount')
            ->limit(10)
            ->get(['id', 'name', 'budget_amount', 'status', 'organization_id'])
            ->map(function ($project) {
                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'budget_amount' => round($project->budget_amount, 2),
                    'status' => $project->status,
                ];
            });

        $overdueProjects = Project::query()
            ->whereIn('organization_id', function ($subQuery) use ($query) {
                $subQuery->select('organization_id')
                    ->from((new Project)->getTable())
                    ->whereIn('organization_id', $query->getBindings());
            })
            ->where('status', 'active')
            ->where('end_date', '<', now())
            ->limit(10)
            ->get(['id', 'name', 'end_date', 'status'])
            ->map(function ($project) {
                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'end_date' => $project->end_date?->format('Y-m-d'),
                    'days_overdue' => $project->end_date ? now()->diffInDays($project->end_date) : 0,
                ];
            });

        return [
            'by_budget' => $topByBudget,
            'overdue' => $overdueProjects,
        ];
    }

    protected function exportProjectsReport(array $data, string $format): StreamedResponse
    {
        $headers = [
            'Организация',
            'Количество проектов',
            'Общий бюджет',
            'Сумма контрактов',
            'Выполнено работ',
            'Процент выполнения',
        ];

        $rows = [];
        foreach ($data['by_organization'] as $org) {
            $rows[] = [
                $org['organization_name'],
                $org['projects_count'],
                $org['total_budget'],
                $org['contracts_amount'],
                $org['completed_works'],
                $org['completion_percentage'] . '%',
            ];
        }

        $filename = 'holding_projects_report_' . now()->format('Y-m-d_His');

        if ($format === 'csv') {
            return $this->csvExporter->streamDownload($filename . '.csv', $headers, $rows);
        }

        return $this->excelExporter->streamDownload($filename . '.xlsx', $headers, $rows);
    }

    protected function exportContractsReport(array $data, string $format): StreamedResponse
    {
        $headers = [
            'Подрядчик',
            'Контактное лицо',
            'Телефон',
            'Количество контрактов',
            'Сумма контрактов',
            'Оплачено',
            'Остаток',
            'Процент оплаты',
            'Организации',
        ];

        $rows = [];
        $contractors = $data['by_contractor']['data'] ?? [];
        
        foreach ($contractors as $contractor) {
            $rows[] = [
                $contractor['contractor_name'],
                $contractor['contact_person'] ?? '',
                $contractor['phone'] ?? '',
                $contractor['contracts_count'],
                $contractor['total_amount'],
                $contractor['total_paid'],
                $contractor['remaining_amount'],
                $contractor['payment_percentage'] . '%',
                $contractor['organizations'] ?? '',
            ];
        }

        $filename = 'holding_contracts_report_' . now()->format('Y-m-d_His');

        if ($format === 'csv') {
            return $this->csvExporter->streamDownload($filename . '.csv', $headers, $rows);
        }

        return $this->excelExporter->streamDownload($filename . '.xlsx', $headers, $rows);
    }
}

