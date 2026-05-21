<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Landing\Report\ConsolidatedActResource;
use App\Http\Resources\Api\V1\Landing\Report\ConsolidatedCompletedWorkResource;
use App\Http\Resources\Api\V1\Landing\Report\ConsolidatedContractResource;
use App\Http\Resources\Api\V1\Landing\Report\ConsolidatedProjectResource;
use App\Http\Responses\LandingResponse;
use App\Services\Export\CsvExporterService;
use App\Services\Landing\HoldingReportService;
use App\Services\Landing\MultiOrganizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

use function trans_message;

class HoldingSummaryController extends Controller
{
    public function __construct(
        protected MultiOrganizationService $multiOrgService,
        protected HoldingReportService $holdingService,
        protected CsvExporterService $csvExporterService,
    ) {
    }

    public function summary(Request $request): Response
    {
        $user = Auth::user();
        $orgIds = $this->multiOrgService->getAccessibleOrganizations($user)->pluck('id')->all();

        $filters = [
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'status' => $request->input('status'),
            'is_approved' => $request->input('is_approved'),
        ];

        if ($request->filled('export') && strtolower((string) $request->input('export')) === 'csv') {
            return $this->exportSectionCsv((string) $request->input('section'), $orgIds, $filters);
        }

        $organizations = $this->holdingService->getOrganizationsInfo($orgIds);
        $projects = $this->holdingService->getConsolidatedProjects($orgIds, $filters);
        $contracts = $this->holdingService->getConsolidatedContracts($orgIds, $filters);
        $acts = $this->holdingService->getConsolidatedActs($orgIds, $filters);
        $completedWorks = $this->holdingService->getConsolidatedCompletedWorks($orgIds, $filters);
        $stats = $this->holdingService->getGlobalStats($orgIds, $filters);

        return LandingResponse::success([
            'organizations' => $organizations,
            'projects' => ConsolidatedProjectResource::collection($projects),
            'contracts' => ConsolidatedContractResource::collection($contracts),
            'acts' => ConsolidatedActResource::collection($acts),
            'completed_works' => ConsolidatedCompletedWorkResource::collection($completedWorks),
            'stats' => $stats,
        ], trans_message('landing.holding_summary.loaded'));
    }

    private function exportSectionCsv(string $section, array $orgIds, array $filters): mixed
    {
        switch ($section) {
            case 'projects':
                $data = $this->holdingService->getConsolidatedProjects($orgIds, $filters);
                $mapping = [
                    trans_message('landing.holding_summary.csv.id') => 'id',
                    trans_message('landing.holding_summary.csv.organization') => 'organization.name',
                    trans_message('landing.holding_summary.csv.name') => 'name',
                    trans_message('landing.holding_summary.csv.status') => 'status',
                    trans_message('landing.holding_summary.csv.start') => 'start_date',
                    trans_message('landing.holding_summary.csv.end') => 'end_date',
                ];
                $filename = 'projects_summary_' . date('Ymd_His');
                break;
            case 'contracts':
                $data = $this->holdingService->getConsolidatedContracts($orgIds, $filters);
                $mapping = [
                    trans_message('landing.holding_summary.csv.id') => 'id',
                    trans_message('landing.holding_summary.csv.organization') => 'organization.name',
                    trans_message('landing.holding_summary.csv.number') => 'number',
                    trans_message('landing.holding_summary.csv.date') => 'date',
                    trans_message('landing.holding_summary.csv.total_amount') => 'total_amount',
                    trans_message('landing.holding_summary.csv.status') => 'status',
                ];
                $filename = 'contracts_summary_' . date('Ymd_His');
                break;
            case 'acts':
                $data = $this->holdingService->getConsolidatedActs($orgIds, $filters);
                $mapping = [
                    trans_message('landing.holding_summary.csv.id') => 'id',
                    trans_message('landing.holding_summary.csv.organization') => 'organization.name',
                    trans_message('landing.holding_summary.csv.contract') => 'contract.number',
                    trans_message('landing.holding_summary.csv.amount') => 'amount',
                    trans_message('landing.holding_summary.csv.approved') => 'is_approved',
                    trans_message('landing.holding_summary.csv.date') => 'date',
                ];
                $filename = 'acts_summary_' . date('Ymd_His');
                break;
            case 'completed_works':
                $data = $this->holdingService->getConsolidatedCompletedWorks($orgIds, $filters);
                $mapping = [
                    trans_message('landing.holding_summary.csv.id') => 'id',
                    trans_message('landing.holding_summary.csv.organization') => 'organization.name',
                    trans_message('landing.holding_summary.csv.project') => 'project.name',
                    trans_message('landing.holding_summary.csv.contract') => 'contract.number',
                    trans_message('landing.holding_summary.csv.work_type') => 'work_type.name',
                    trans_message('landing.holding_summary.csv.quantity') => 'quantity',
                    trans_message('landing.holding_summary.csv.price') => 'price',
                    trans_message('landing.holding_summary.csv.total_amount') => 'total_amount',
                    trans_message('landing.holding_summary.csv.date') => 'completion_date',
                    trans_message('landing.holding_summary.csv.status') => 'status',
                ];
                $filename = 'completed_works_' . date('Ymd_His');
                break;
            default:
                return LandingResponse::error(trans_message('landing.holding_summary.invalid_section'), 400);
        }

        $prepared = $this->csvExporterService->prepareDataForExport($data, $mapping);

        return $this->csvExporterService->streamDownload($filename . '.csv', $prepared['headers'], $prepared['data']);
    }
}
