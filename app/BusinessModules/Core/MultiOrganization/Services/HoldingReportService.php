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

    public function getIntraGroupReport(HoldingReportRequest $request, int $holdingId): array|StreamedResponse
    {
        $holding = $this->validateHoldingAccess($holdingId);
        $availableOrgIds = $this->scope->getOrganizationScope($holdingId);
        $filters = $this->extractFilters($request);

        $selectedOrgIds = !empty($filters['organization_ids']) 
            ? array_intersect($filters['organization_ids'], $availableOrgIds)
            : $availableOrgIds;

        Log::info('Holding intra-group report requested', [
            'holding_id' => $holdingId,
            'organizations' => count($selectedOrgIds),
        ]);

        $projects = $this->getProjectsWithIntraGroupStructure($holdingId, $selectedOrgIds, $filters);

        $summary = $this->calculateIntraGroupSummary($projects);

        $result = [
            'title' => 'Отчет по внутригрупповым проектам холдинга',
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
            'projects' => $projects,
            'generated_at' => now()->toISOString(),
        ];

        if ($request->input('export_format')) {
            return $this->exportIntraGroupReport($result, $request->input('export_format'));
        }

        return $result;
    }

    public function getConsolidatedReport(HoldingReportRequest $request, int $holdingId): array|StreamedResponse
    {
        $holding = $this->validateHoldingAccess($holdingId);
        $availableOrgIds = $this->scope->getOrganizationScope($holdingId);
        $filters = $this->extractFilters($request);

        $selectedOrgIds = !empty($filters['organization_ids']) 
            ? array_intersect($filters['organization_ids'], $availableOrgIds)
            : $availableOrgIds;

        Log::info('Holding consolidated report requested', [
            'holding_id' => $holdingId,
            'organizations' => count($selectedOrgIds),
        ]);

        $detailedData = $this->getConsolidatedDetailedData($selectedOrgIds, $filters);

        $summary = $this->calculateConsolidatedSummary($detailedData);

        $result = [
            'title' => 'Консолидированный отчет холдинга',
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
            'data' => $detailedData,
            'generated_at' => now()->toISOString(),
        ];

        if ($request->input('export_format')) {
            return $this->exportConsolidatedReport($result, $request->input('export_format'));
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
                DB::raw('STRING_AGG(DISTINCT orgs.name, \', \') as organizations'),
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

    protected function getProjectsWithIntraGroupStructure(int $holdingId, array $orgIds, array $filters): array
    {
        $query = Project::query()
            ->where('organization_id', $holdingId)
            ->with(['organization:id,name', 'contracts.contractor']);

        $this->applyProjectFilters($query, $filters);

        $projects = $query->get();
        $result = [];

        foreach ($projects as $project) {
            $projectContracts = Contract::where('project_id', $project->id)
                ->where('organization_id', $holdingId)
                ->with(['contractor:id,name,organization_id'])
                ->get();

            $totalHeadAmount = $projectContracts->sum('total_amount');
            $totalHeadPaid = DB::table('contract_payments')
                ->whereIn('contract_id', $projectContracts->pluck('id'))
                ->sum('amount');

            $childOrganizationsData = [];
            
            foreach ($projectContracts as $contract) {
                $contractor = $contract->contractor;
                
                if (!$contractor || !$contractor->organization_id) {
                    continue;
                }

                if (!in_array($contractor->organization_id, $orgIds)) {
                    continue;
                }

                $childOrg = Organization::find($contractor->organization_id);
                
                if (!$childOrg || $childOrg->parent_organization_id != $holdingId) {
                    continue;
                }

                $subcontracts = Contract::where('project_id', $project->id)
                    ->where('organization_id', $childOrg->id)
                    ->whereNull('deleted_at')
                    ->get();

                $totalSubAmount = $subcontracts->sum('total_amount');
                $totalSubPaid = DB::table('contract_payments')
                    ->whereIn('contract_id', $subcontracts->pluck('id'))
                    ->sum('amount');

                $existingIndex = array_search($childOrg->id, array_column($childOrganizationsData, 'organization_id'));

                if ($existingIndex !== false) {
                    $childOrganizationsData[$existingIndex]['parent_contract_amount'] += (float) $contract->total_amount;
                    $childOrganizationsData[$existingIndex]['subcontracts_count'] += $subcontracts->count();
                    $childOrganizationsData[$existingIndex]['subcontracts_amount'] += $totalSubAmount;
                    $childOrganizationsData[$existingIndex]['subcontracts_paid'] += $totalSubPaid;
                } else {
                    $childOrganizationsData[] = [
                        'organization_id' => $childOrg->id,
                        'organization_name' => $childOrg->name,
                        'role' => 'subcontractor',
                        'parent_contract_id' => $contract->id,
                        'parent_contract_number' => $contract->number,
                        'parent_contract_amount' => (float) $contract->total_amount,
                        'subcontracts_count' => $subcontracts->count(),
                        'subcontracts_amount' => round($totalSubAmount, 2),
                        'subcontracts_paid' => round($totalSubPaid, 2),
                        'subcontracts_remaining' => round($totalSubAmount - $totalSubPaid, 2),
                        'margin' => round($contract->total_amount - $totalSubAmount, 2),
                        'margin_percentage' => $contract->total_amount > 0 
                            ? round((($contract->total_amount - $totalSubAmount) / $contract->total_amount) * 100, 2) 
                            : 0,
                        'subcontracts' => $subcontracts->map(function ($sub) {
                            $paid = DB::table('contract_payments')
                                ->where('contract_id', $sub->id)
                                ->sum('amount');

                            return [
                                'id' => $sub->id,
                                'number' => $sub->number,
                                'contractor_name' => $sub->contractor?->name,
                                'amount' => round($sub->total_amount, 2),
                                'paid' => round($paid, 2),
                                'status' => $sub->status,
                            ];
                        })->toArray(),
                    ];
                }
            }

            $result[] = [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'project_address' => $project->address,
                'project_status' => $project->status,
                'project_budget' => round($project->budget_amount ?? 0, 2),
                'head_organization' => [
                    'id' => $holdingId,
                    'name' => $project->organization->name,
                    'contracts_count' => $projectContracts->count(),
                    'contracts_amount' => round($totalHeadAmount, 2),
                    'contracts_paid' => round($totalHeadPaid, 2),
                    'contracts_remaining' => round($totalHeadAmount - $totalHeadPaid, 2),
                ],
                'child_organizations' => $childOrganizationsData,
                'financial_flow' => [
                    'total_inflow' => round($totalHeadAmount, 2),
                    'total_outflow' => round(collect($childOrganizationsData)->sum('parent_contract_amount'), 2),
                    'net_margin' => round($totalHeadAmount - collect($childOrganizationsData)->sum('parent_contract_amount'), 2),
                ],
            ];
        }

        return $result;
    }

    protected function calculateIntraGroupSummary(array $projects): array
    {
        $totalProjects = count($projects);
        $totalHeadContracts = array_sum(array_column(array_column($projects, 'head_organization'), 'contracts_count'));
        $totalHeadAmount = array_sum(array_column(array_column($projects, 'head_organization'), 'contracts_amount'));
        $totalHeadPaid = array_sum(array_column(array_column($projects, 'head_organization'), 'contracts_paid'));

        $allChildOrgs = [];
        foreach ($projects as $project) {
            foreach ($project['child_organizations'] as $child) {
                $allChildOrgs[] = $child;
            }
        }

        $totalSubcontracts = array_sum(array_column($allChildOrgs, 'subcontracts_count'));
        $totalSubAmount = array_sum(array_column($allChildOrgs, 'subcontracts_amount'));
        $totalSubPaid = array_sum(array_column($allChildOrgs, 'subcontracts_paid'));
        $totalMargin = array_sum(array_column($allChildOrgs, 'margin'));

        $uniqueChildOrgs = count(array_unique(array_column($allChildOrgs, 'organization_id')));

        return [
            'total_projects' => $totalProjects,
            'head_organization' => [
                'contracts_count' => $totalHeadContracts,
                'contracts_amount' => round($totalHeadAmount, 2),
                'contracts_paid' => round($totalHeadPaid, 2),
                'contracts_remaining' => round($totalHeadAmount - $totalHeadPaid, 2),
            ],
            'child_organizations' => [
                'unique_count' => $uniqueChildOrgs,
                'total_contracts_received' => count($allChildOrgs),
                'subcontracts_count' => $totalSubcontracts,
                'subcontracts_amount' => round($totalSubAmount, 2),
                'subcontracts_paid' => round($totalSubPaid, 2),
                'subcontracts_remaining' => round($totalSubAmount - $totalSubPaid, 2),
            ],
            'financial_analysis' => [
                'total_margin' => round($totalMargin, 2),
                'average_margin_percentage' => count($allChildOrgs) > 0 
                    ? round(array_sum(array_column($allChildOrgs, 'margin_percentage')) / count($allChildOrgs), 2)
                    : 0,
                'internal_efficiency' => $totalHeadAmount > 0 
                    ? round(($totalMargin / $totalHeadAmount) * 100, 2)
                    : 0,
            ],
        ];
    }

    protected function exportIntraGroupReport(array $data, string $format): StreamedResponse
    {
        $headers = [
            'Проект',
            'Организация',
            'Роль',
            'Контрактов',
            'Сумма контрактов',
            'Оплачено',
            'Маржа',
            'Маржа %',
        ];

        $rows = [];
        
        foreach ($data['projects'] as $project) {
            $rows[] = [
                $project['project_name'],
                $project['head_organization']['name'],
                'Головная',
                $project['head_organization']['contracts_count'],
                $project['head_organization']['contracts_amount'],
                $project['head_organization']['contracts_paid'],
                '',
                '',
            ];

            foreach ($project['child_organizations'] as $child) {
                $rows[] = [
                    '',
                    $child['organization_name'],
                    'Дочерняя (подрядчик)',
                    $child['subcontracts_count'],
                    $child['subcontracts_amount'],
                    $child['subcontracts_paid'],
                    $child['margin'],
                    $child['margin_percentage'] . '%',
                ];
            }

            $rows[] = ['', '', '', '', '', '', '', ''];
        }

        $filename = 'holding_intragroup_report_' . now()->format('Y-m-d_His');

        if ($format === 'csv') {
            return $this->csvExporter->streamDownload($filename . '.csv', $headers, $rows);
        }

        return $this->excelExporter->streamDownload($filename . '.xlsx', $headers, $rows);
    }

    protected function getConsolidatedDetailedData(array $orgIds, array $filters): array
    {
        $organizations = Organization::whereIn('id', $orgIds)->get();
        $result = [];

        foreach ($organizations as $org) {
            $projectsQuery = Project::where('organization_id', $org->id);
            $this->applyProjectFilters($projectsQuery, $filters);
            $projects = $projectsQuery->get();

            $orgData = [
                'organization_id' => $org->id,
                'organization_name' => $org->name,
                'organization_type' => $org->parent_organization_id ? 'child' : 'holding',
                'projects_count' => $projects->count(),
                'projects' => [],
            ];

            foreach ($projects as $project) {
                $contractsQuery = Contract::where('project_id', $project->id)
                    ->where('organization_id', $org->id)
                    ->with([
                        'contractor', 
                        'payments', 
                        'performanceActs', 
                        'completedWorks.workType',
                        'agreements',
                        'specifications',
                        'childContracts.contractor',
                        'parentContract'
                    ]);
                
                $this->applyContractFilters($contractsQuery, $filters);
                $contracts = $contractsQuery->get();
                
                $materialReceipts = DB::table('material_receipts')
                    ->join('materials', 'material_receipts.material_id', '=', 'materials.id')
                    ->join('suppliers', 'material_receipts.supplier_id', '=', 'suppliers.id')
                    ->where('material_receipts.project_id', $project->id)
                    ->where('material_receipts.organization_id', $org->id)
                    ->select(
                        'material_receipts.id',
                        'material_receipts.receipt_date',
                        'material_receipts.quantity',
                        'material_receipts.price',
                        'material_receipts.total_amount',
                        'material_receipts.document_number',
                        'material_receipts.status',
                        'materials.name as material_name',
                        'materials.code as material_code',
                        'suppliers.name as supplier_name'
                    )
                    ->get();
                
                $materialWriteOffs = DB::table('material_write_offs')
                    ->join('materials', 'material_write_offs.material_id', '=', 'materials.id')
                    ->where('material_write_offs.project_id', $project->id)
                    ->where('material_write_offs.organization_id', $org->id)
                    ->select(
                        'material_write_offs.id',
                        'material_write_offs.write_off_date',
                        'material_write_offs.quantity',
                        'material_write_offs.notes',
                        'materials.name as material_name',
                        'materials.code as material_code'
                    )
                    ->get();

                $projectData = [
                    'project_id' => $project->id,
                    'project_name' => $project->name,
                    'project_address' => $project->address,
                    'project_status' => $project->status,
                    'project_budget' => round($project->budget_amount ?? 0, 2),
                    'customer' => $project->customer,
                    'designer' => $project->designer,
                    'site_area_m2' => round($project->site_area_m2 ?? 0, 2),
                    'start_date' => $project->start_date?->format('Y-m-d'),
                    'end_date' => $project->end_date?->format('Y-m-d'),
                    'is_archived' => $project->is_archived,
                    'contracts_count' => $contracts->count(),
                    'contracts' => [],
                    'material_receipts' => $materialReceipts->map(function ($receipt) {
                        return [
                            'id' => $receipt->id,
                            'material_name' => $receipt->material_name,
                            'material_code' => $receipt->material_code,
                            'supplier_name' => $receipt->supplier_name,
                            'quantity' => round($receipt->quantity, 3),
                            'price' => round($receipt->price ?? 0, 2),
                            'total_amount' => round($receipt->total_amount ?? 0, 2),
                            'receipt_date' => $receipt->receipt_date,
                            'document_number' => $receipt->document_number,
                            'status' => $receipt->status,
                        ];
                    })->toArray(),
                    'material_write_offs' => $materialWriteOffs->map(function ($writeOff) {
                        return [
                            'id' => $writeOff->id,
                            'material_name' => $writeOff->material_name,
                            'material_code' => $writeOff->material_code,
                            'quantity' => round($writeOff->quantity, 3),
                            'write_off_date' => $writeOff->write_off_date,
                            'notes' => $writeOff->notes,
                        ];
                    })->toArray(),
                    'materials_summary' => [
                        'receipts_count' => $materialReceipts->count(),
                        'receipts_total' => round($materialReceipts->sum('total_amount'), 2),
                        'write_offs_count' => $materialWriteOffs->count(),
                    ],
                ];

                foreach ($contracts as $contract) {
                    $contractor = $contract->contractor;
                    $payments = $contract->payments;
                    $acts = $contract->performanceActs->where('is_approved', true);
                    $works = $contract->completedWorks->where('status', 'confirmed');

                    $totalPaid = $payments->sum('amount');
                    $totalActs = $acts->sum('amount');
                    $totalWorks = $works->sum('total_amount');

                    $contractData = [
                        'contract_id' => $contract->id,
                        'contract_number' => $contract->number,
                        'contract_date' => $contract->date?->format('Y-m-d'),
                        'contract_status' => $contract->status->value ?? $contract->status,
                        'work_type_category' => $contract->work_type_category,
                        'start_date' => $contract->start_date?->format('Y-m-d'),
                        'end_date' => $contract->end_date?->format('Y-m-d'),
                        
                        'contractor' => $contractor ? [
                            'id' => $contractor->id,
                            'name' => $contractor->name,
                            'inn' => $contractor->inn,
                            'contact_person' => $contractor->contact_person,
                            'phone' => $contractor->phone,
                            'email' => $contractor->email,
                            'contractor_type' => $contractor->contractor_type,
                            'organization_id' => $contractor->organization_id,
                        ] : null,
                        
                        'financial' => [
                            'total_amount' => round($contract->total_amount, 2),
                            'gp_amount' => round($contract->gp_amount ?? 0, 2),
                            'gp_percentage' => round($contract->gp_percentage ?? 0, 2),
                            'subcontract_amount' => round($contract->subcontract_amount ?? 0, 2),
                            'planned_advance' => round($contract->planned_advance_amount ?? 0, 2),
                            'actual_advance' => round($contract->actual_advance_amount ?? 0, 2),
                            'total_paid' => round($totalPaid, 2),
                            'total_acts' => round($totalActs, 2),
                            'total_works' => round($totalWorks, 2),
                            'remaining' => round($contract->total_amount - $totalPaid, 2),
                            'completion_percentage' => $contract->total_amount > 0 
                                ? round(($totalActs / $contract->total_amount) * 100, 2) 
                                : 0,
                            'payment_percentage' => $contract->total_amount > 0 
                                ? round(($totalPaid / $contract->total_amount) * 100, 2) 
                                : 0,
                        ],
                        
                        'payments' => $payments->map(function ($payment) {
                            return [
                                'id' => $payment->id,
                                'amount' => round($payment->amount, 2),
                                'payment_date' => $payment->payment_date?->format('Y-m-d'),
                                'payment_type' => $payment->payment_type,
                                'notes' => $payment->notes,
                            ];
                        })->toArray(),
                        
                        'acts' => $acts->map(function ($act) {
                            return [
                                'id' => $act->id,
                                'number' => $act->number,
                                'amount' => round($act->amount, 2),
                                'act_date' => $act->act_date?->format('Y-m-d'),
                                'is_approved' => $act->is_approved,
                            ];
                        })->toArray(),
                        
                        'completed_works' => $works->map(function ($work) {
                            return [
                                'id' => $work->id,
                                'work_type' => $work->workType?->name,
                                'quantity' => round($work->quantity ?? 0, 3),
                                'price' => round($work->price ?? 0, 2),
                                'total_amount' => round($work->total_amount ?? 0, 2),
                                'completion_date' => $work->completion_date?->format('Y-m-d'),
                                'status' => $work->status,
                            ];
                        })->toArray(),
                        
                        'payments_count' => $payments->count(),
                        'acts_count' => $acts->count(),
                        'works_count' => $works->count(),
                        
                        'agreements' => $contract->agreements->map(function ($agreement) {
                            return [
                                'id' => $agreement->id,
                                'number' => $agreement->number,
                                'date' => $agreement->date?->format('Y-m-d'),
                                'amount_change' => round($agreement->amount_change ?? 0, 2),
                                'description' => $agreement->description,
                            ];
                        })->toArray(),
                        
                        'specifications' => $contract->specifications->map(function ($spec) {
                            return [
                                'id' => $spec->id,
                                'name' => $spec->name,
                                'total_amount' => round($spec->total_amount ?? 0, 2),
                            ];
                        })->toArray(),
                        
                        'child_contracts' => $contract->childContracts->map(function ($child) {
                            $childPaid = DB::table('contract_payments')
                                ->where('contract_id', $child->id)
                                ->sum('amount');
                            
                            return [
                                'id' => $child->id,
                                'number' => $child->number,
                                'contractor_name' => $child->contractor?->name,
                                'amount' => round($child->total_amount, 2),
                                'paid' => round($childPaid, 2),
                                'status' => $child->status->value ?? $child->status,
                            ];
                        })->toArray(),
                        
                        'parent_contract' => $contract->parentContract ? [
                            'id' => $contract->parentContract->id,
                            'number' => $contract->parentContract->number,
                            'amount' => round($contract->parentContract->total_amount, 2),
                        ] : null,
                        
                        'agreements_count' => $contract->agreements->count(),
                        'specifications_count' => $contract->specifications->count(),
                        'child_contracts_count' => $contract->childContracts->count(),
                    ];

                    $projectData['contracts'][] = $contractData;
                }

                if ($contracts->count() > 0) {
                    $orgData['projects'][] = $projectData;
                }
            }

            if (count($orgData['projects']) > 0) {
                $result[] = $orgData;
            }
        }

        return $result;
    }

    protected function calculateConsolidatedSummary(array $data): array
    {
        $totalOrganizations = count($data);
        $totalProjects = 0;
        $totalContracts = 0;
        $totalAmount = 0;
        $totalPaid = 0;
        $totalActs = 0;
        $totalPayments = 0;
        $totalWorks = 0;
        $totalAgreements = 0;
        $totalSpecifications = 0;
        $totalChildContracts = 0;
        $totalMaterialReceipts = 0;
        $totalMaterialReceiptsAmount = 0;
        $totalMaterialWriteOffs = 0;
        $uniqueContractors = [];

        foreach ($data as $org) {
            $totalProjects += count($org['projects']);
            
            foreach ($org['projects'] as $project) {
                $totalContracts += count($project['contracts']);
                $totalMaterialReceipts += $project['materials_summary']['receipts_count'];
                $totalMaterialReceiptsAmount += $project['materials_summary']['receipts_total'];
                $totalMaterialWriteOffs += $project['materials_summary']['write_offs_count'];
                
                foreach ($project['contracts'] as $contract) {
                    $totalAmount += $contract['financial']['total_amount'];
                    $totalPaid += $contract['financial']['total_paid'];
                    $totalActs += $contract['financial']['total_acts'];
                    $totalPayments += $contract['payments_count'];
                    $totalWorks += $contract['works_count'];
                    $totalAgreements += $contract['agreements_count'];
                    $totalSpecifications += $contract['specifications_count'];
                    $totalChildContracts += $contract['child_contracts_count'];
                    
                    if ($contract['contractor']) {
                        $uniqueContractors[$contract['contractor']['id']] = $contract['contractor']['name'];
                    }
                }
            }
        }

        return [
            'total_organizations' => $totalOrganizations,
            'total_projects' => $totalProjects,
            'total_contracts' => $totalContracts,
            'total_contractors' => count($uniqueContractors),
            'financial' => [
                'total_amount' => round($totalAmount, 2),
                'total_paid' => round($totalPaid, 2),
                'total_acts' => round($totalActs, 2),
                'remaining' => round($totalAmount - $totalPaid, 2),
                'completion_percentage' => $totalAmount > 0 ? round(($totalActs / $totalAmount) * 100, 2) : 0,
                'payment_percentage' => $totalAmount > 0 ? round(($totalPaid / $totalAmount) * 100, 2) : 0,
            ],
            'details' => [
                'total_payments' => $totalPayments,
                'total_acts' => $totalActs,
                'total_completed_works' => $totalWorks,
                'total_agreements' => $totalAgreements,
                'total_specifications' => $totalSpecifications,
                'total_child_contracts' => $totalChildContracts,
            ],
            'materials' => [
                'total_receipts' => $totalMaterialReceipts,
                'receipts_amount' => round($totalMaterialReceiptsAmount, 2),
                'total_write_offs' => $totalMaterialWriteOffs,
            ],
        ];
    }

    protected function exportConsolidatedReport(array $data, string $format): StreamedResponse
    {
        $headers = [
            'Организация',
            'Проект',
            'Номер контракта',
            'Дата контракта',
            'Статус',
            'Подрядчик',
            'ИНН',
            'Контактное лицо',
            'Телефон',
            'Тип работ',
            'Сумма контракта',
            'ГП сумма',
            'ГП %',
            'Оплачено',
            'Актов',
            'Работ выполнено',
            'Остаток',
            'Выполнение %',
            'Оплата %',
            'Кол-во платежей',
            'Кол-во актов',
            'Кол-во работ',
        ];

        $rows = [];
        
        foreach ($data['data'] as $org) {
            foreach ($org['projects'] as $project) {
                foreach ($project['contracts'] as $contract) {
                    $contractor = $contract['contractor'];
                    
                    $rows[] = [
                        $org['organization_name'],
                        $project['project_name'],
                        $contract['contract_number'],
                        $contract['contract_date'],
                        $contract['contract_status'],
                        $contractor ? $contractor['name'] : '-',
                        $contractor ? $contractor['inn'] : '-',
                        $contractor ? $contractor['contact_person'] : '-',
                        $contractor ? $contractor['phone'] : '-',
                        $contract['work_type_category'] ?? '-',
                        $contract['financial']['total_amount'],
                        $contract['financial']['gp_amount'],
                        $contract['financial']['gp_percentage'] . '%',
                        $contract['financial']['total_paid'],
                        $contract['financial']['total_acts'],
                        $contract['financial']['total_works'],
                        $contract['financial']['remaining'],
                        $contract['financial']['completion_percentage'] . '%',
                        $contract['financial']['payment_percentage'] . '%',
                        $contract['payments_count'],
                        $contract['acts_count'],
                        $contract['works_count'],
                    ];
                }
            }
        }

        $filename = 'holding_consolidated_report_' . now()->format('Y-m-d_His');

        if ($format === 'csv') {
            return $this->csvExporter->streamDownload($filename . '.csv', $headers, $rows);
        }

        return $this->excelExporter->streamDownload($filename . '.xlsx', $headers, $rows);
    }
}

