<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Services\Landing\MultiOrganizationService;
use App\Services\Landing\HoldingReportService;
use App\Services\Export\CsvExporterService;
use App\Http\Resources\Api\V1\Landing\Report\ConsolidatedContractResource;
use App\Http\Resources\Api\V1\Landing\Report\ConsolidatedActResource;
use App\Http\Resources\Api\V1\Landing\Report\ConsolidatedProjectResource;
use App\Http\Resources\Api\V1\Landing\Report\ConsolidatedCompletedWorkResource;

class HoldingSummaryController extends Controller
{
    public function __construct(
        protected MultiOrganizationService $multiOrgService,
        protected HoldingReportService $holdingService,
        protected CsvExporterService $csvExporterService,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $user = Auth::user();
        $orgIds = $this->multiOrgService->getAccessibleOrganizations($user)->pluck('id')->all();

        $filters = [
            'date_from' => $request->input('date_from'),
            'date_to'   => $request->input('date_to'),
            'status'    => $request->input('status'),
            'is_approved' => $request->input('is_approved'),
        ];

        // Р•СЃР»Рё С‚СЂРµР±СѓРµС‚СЃСЏ CSV
        if ($request->filled('export') && strtolower($request->input('export')) === 'csv') {
            $section = $request->input('section');
            return $this->exportSectionCsv($section, $orgIds, $filters);
        }

        $organizations = $this->holdingService->getOrganizationsInfo($orgIds);
        $projects      = $this->holdingService->getConsolidatedProjects($orgIds, $filters);
        $contracts     = $this->holdingService->getConsolidatedContracts($orgIds, $filters);
        $acts          = $this->holdingService->getConsolidatedActs($orgIds, $filters);
        $completedWorks = $this->holdingService->getConsolidatedCompletedWorks($orgIds, $filters);
        $stats         = $this->holdingService->getGlobalStats($orgIds, $filters);

        return \App\Http\Responses\LandingResponse::fromPayload([
            'success' => true,
            'data' => [
                'organizations' => $organizations,
                'projects'      => ConsolidatedProjectResource::collection($projects),
                'contracts'     => ConsolidatedContractResource::collection($contracts),
                'acts'          => ConsolidatedActResource::collection($acts),
                'completed_works' => ConsolidatedCompletedWorkResource::collection($completedWorks),
                'stats'         => $stats,
            ]
        ]);
    }

    private function exportSectionCsv(string $section, array $orgIds, array $filters)
    {
        switch ($section) {
            case 'projects':
                $data = $this->holdingService->getConsolidatedProjects($orgIds, $filters);
                $mapping = [
                    'ID' => 'id',
                    'РћСЂРіР°РЅРёР·Р°С†РёСЏ' => 'organization.name',
                    'РќР°Р·РІР°РЅРёРµ' => 'name',
                    'РЎС‚Р°С‚СѓСЃ' => 'status',
                    'РќР°С‡Р°Р»Рѕ' => 'start_date',
                    'РћРєРѕРЅС‡Р°РЅРёРµ' => 'end_date',
                ];
                $filename = 'projects_summary_' . date('Ymd_His');
                break;
            case 'contracts':
                $data = $this->holdingService->getConsolidatedContracts($orgIds, $filters);
                $mapping = [
                    'ID' => 'id',
                    'РћСЂРіР°РЅРёР·Р°С†РёСЏ' => 'organization.name',
                    'РќРѕРјРµСЂ' => 'number',
                    'Р”Р°С‚Р°' => 'date',
                    'РЎСѓРјРјР°' => 'total_amount',
                    'РЎС‚Р°С‚СѓСЃ' => 'status',
                ];
                $filename = 'contracts_summary_' . date('Ymd_His');
                break;
            case 'acts':
                $data = $this->holdingService->getConsolidatedActs($orgIds, $filters);
                $mapping = [
                    'ID' => 'id',
                    'РћСЂРіР°РЅРёР·Р°С†РёСЏ' => 'organization.name',
                    'Р”РѕРіРѕРІРѕСЂ' => 'contract.number',
                    'РЎСѓРјРјР°' => 'amount',
                    'РЈС‚РІРµСЂР¶РґРµРЅ' => 'is_approved',
                    'Р”Р°С‚Р°' => 'date',
                ];
                $filename = 'acts_summary_' . date('Ymd_His');
                break;
            case 'completed_works':
                $data = $this->holdingService->getConsolidatedCompletedWorks($orgIds, $filters);
                $mapping = [
                    'ID' => 'id',
                    'РћСЂРіР°РЅРёР·Р°С†РёСЏ' => 'organization.name',
                    'РџСЂРѕРµРєС‚' => 'project.name',
                    'Р”РѕРіРѕРІРѕСЂ' => 'contract.number',
                    'Р’РёРґ СЂР°Р±РѕС‚' => 'work_type.name',
                    'РљРѕР»-РІРѕ' => 'quantity',
                    'Р¦РµРЅР°' => 'price',
                    'РЎСѓРјРјР°' => 'total_amount',
                    'Р”Р°С‚Р°' => 'completion_date',
                    'РЎС‚Р°С‚СѓСЃ' => 'status',
                ];
                $filename = 'completed_works_' . date('Ymd_His');
                break;
            default:
                return \App\Http\Responses\LandingResponse::fromPayload(['message' => 'Invalid section for export'], 400);
        }

        $prepared = $this->csvExporterService->prepareDataForExport($data, $mapping);
        return $this->csvExporterService->streamDownload($filename . '.csv', $prepared['headers'], $prepared['data']);
    }
} 