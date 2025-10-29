<?php

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller; // Убедимся, что базовый контроллер существует и используется
use App\Services\Contract\ContractPerformanceActService;
use App\Http\Requests\Api\V1\Admin\Contract\PerformanceAct\StoreContractPerformanceActRequest;
use App\Http\Requests\Api\V1\Admin\Contract\PerformanceAct\UpdateContractPerformanceActRequest;
use App\Http\Resources\Api\V1\Admin\Contract\PerformanceAct\ContractPerformanceActResource;
use App\Http\Resources\Api\V1\Admin\Contract\PerformanceAct\ContractPerformanceActCollection;
use Illuminate\Http\Request; // Для $request->input()
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth; // Для Auth::user()
use App\Services\Export\ExcelExporterService;
use App\Services\Storage\FileService;
use App\Models\Organization;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Log;
use Exception;

class ContractPerformanceActController extends Controller
{
    protected ContractPerformanceActService $actService;
    protected ExcelExporterService $excelExporter;
    protected FileService $fileService;

    public function __construct(
        ContractPerformanceActService $actService, 
        ExcelExporterService $excelExporter,
        FileService $fileService
    ) {
        $this->actService = $actService;
        $this->excelExporter = $excelExporter;
        $this->fileService = $fileService;
    }
    
    private function validateProjectContext(Request $request, $act): bool
    {
        $projectId = $request->route('project');
        if ($projectId && $act->contract && (int)$act->contract->project_id !== (int)$projectId) {
            return false;
        }
        return true;
    }

    /**
     * Display a listing of the resource for a specific contract.
     */
    public function index(Request $request, int $project, int $contract)
    {
        $organization = $request->attributes->get('current_organization');
        $organizationId = $organization?->id ?? $request->user()?->current_organization_id;
        $projectId = $project;

        try {
            $acts = $this->actService->getAllActsForContract($contract, $organizationId, [], $projectId);
            return new ContractPerformanceActCollection($acts);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to retrieve performance acts', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Store a newly created resource in storage for a specific contract.
     */
    public function store(StoreContractPerformanceActRequest $request, int $project, int $contract)
    {
        $organization = $request->attributes->get('current_organization');
        $organizationId = $organization?->id ?? $request->user()?->current_organization_id;
        $projectId = $project;

        try {
            $actDTO = $request->toDto();
            $act = $this->actService->createActForContract($contract, $organizationId, $actDTO, $projectId);
            return (new ContractPerformanceActResource($act))
                    ->response()
                    ->setStatusCode(Response::HTTP_CREATED);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to create performance act', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, int $project, int $contract, int $act)
    {
        $organization = $request->attributes->get('current_organization');
        $organizationId = $organization?->id ?? $request->user()?->current_organization_id;
        $projectId = $project;

        try {
            $actModel = $this->actService->getActById($act, $contract, $organizationId, $projectId);
            if (!$act) {
                return response()->json(['message' => 'Performance act not found'], Response::HTTP_NOT_FOUND);
            }
            
            if (!$this->validateProjectContext($request, $act)) {
                return response()->json(['message' => 'Performance act not found'], Response::HTTP_NOT_FOUND);
            }
            
            return new ContractPerformanceActResource($act);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to retrieve performance act', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateContractPerformanceActRequest $request, int $project, int $contract, int $act)
    {
        $organization = $request->attributes->get('current_organization');
        $organizationId = $organization?->id ?? $request->user()?->current_organization_id;
        $projectId = $project;
        
        try {
            $existingAct = $this->actService->getActById($act, $contract, $organizationId, $projectId);
            if (!$existingAct) {
                return response()->json(['message' => 'Performance act not found'], Response::HTTP_NOT_FOUND);
            }
            
            if (!$this->validateProjectContext($request, $existingAct)) {
                return response()->json(['message' => 'Performance act not found'], Response::HTTP_NOT_FOUND);
            }
            
            $actDTO = $request->toDto();
            $updatedAct = $this->actService->updateAct($act, $contract, $organizationId, $actDTO, $projectId);
            return new ContractPerformanceActResource($updatedAct);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to update performance act', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, int $project, int $contract, int $act)
    {
        $organization = $request->attributes->get('current_organization');
        $organizationId = $organization?->id ?? $request->user()?->current_organization_id;
        $projectId = $project;

        try {
            $existingAct = $this->actService->getActById($act, $contract, $organizationId, $projectId);
            if (!$existingAct) {
                return response()->json(['message' => 'Performance act not found'], Response::HTTP_NOT_FOUND);
            }
            
            if (!$this->validateProjectContext($request, $existingAct)) {
                return response()->json(['message' => 'Performance act not found'], Response::HTTP_NOT_FOUND);
            }
            
            $this->actService->deleteAct($act, $contract, $organizationId, $projectId);
            return response()->json(null, Response::HTTP_NO_CONTENT);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to delete performance act', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Получить доступные работы для включения в акт
     */
    public function availableWorks(Request $request, int $project, int $contract)
    {
        $projectContext = \App\Http\Middleware\ProjectContextMiddleware::getProjectContext($request);
        $projectId = $project;
        
        $contractModel = \App\Models\Contract::find($contract);
        if (!$contractModel) {
            return response()->json(['message' => 'Contract not found'], Response::HTTP_NOT_FOUND);
        }
        
        $organizationId = $contractModel->organization_id;

        try {
            $works = $this->actService->getAvailableWorksForAct($contract, $organizationId, $projectId);
            return response()->json(['data' => $works]);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to retrieve available works', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Экспорт акта в PDF
     */
    public function exportPdf(Request $request, int $project, int $contract, int $act)
    {
        try {
            $user = $request->user();
            $organizationId = $user->organization_id ?? $user->current_organization_id;
            $projectId = $project;

            if (!$organizationId) {
                return response()->json(['error' => 'Не определена организация пользователя'], 400);
            }

            $actModel = $this->actService->getActById($act, $contract, $organizationId, $projectId);
            if (!$act) {
                return response()->json(['message' => 'Performance act not found'], Response::HTTP_NOT_FOUND);
            }
            
            if (!$this->validateProjectContext($request, $act)) {
                return response()->json(['message' => 'Performance act not found'], Response::HTTP_NOT_FOUND);
            }

            $act->load([
                'contract.project', 
                'contract.contractor',
                'contract.organization',
                'completedWorks.workType',
                'completedWorks.materials',
                'completedWorks.user'
            ]);

            $data = [
                'act' => $act,
                'contract' => $act->contract,
                'project' => $act->contract->project ?? (object)['name' => 'Не указан'],
                'contractor' => $act->contract->contractor ?? (object)['name' => 'Не указан'],
                'works' => $act->completedWorks ?? collect(),
                'total_amount' => $act->amount ?? 0,
                'generated_at' => now()->format('d.m.Y H:i')
            ];

            $pdf = Pdf::loadView('reports.act-report-pdf', $data);
            $pdf->setPaper('A4', 'portrait');

            $filename = "act_" . preg_replace('/[^A-Za-z0-9\-_]/', '_', $actModel->act_document_number) . "_" . now()->format('Y-m-d') . ".pdf";

            return $pdf->download($filename);

        } catch (Exception $e) {
            Log::error('Ошибка экспорта PDF акта', [
                'contract_id' => $contract,
                'act_id' => $act,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Ошибка при экспорте в PDF',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Экспорт акта в Excel
     */
    public function exportExcel(Request $request, int $project, int $contract, int $act)
    {
        try {
            $user = $request->user();
            $organizationId = $user->organization_id ?? $user->current_organization_id;
            $projectId = $project;

            if (!$organizationId) {
                return response()->json(['error' => 'Не определена организация пользователя'], 400);
            }

            $actModel = $this->actService->getActById($act, $contract, $organizationId, $projectId);
            if (!$actModel) {
                return response()->json(['message' => 'Performance act not found'], Response::HTTP_NOT_FOUND);
            }
            
            if (!$this->validateProjectContext($request, $actModel)) {
                return response()->json(['message' => 'Performance act not found'], Response::HTTP_NOT_FOUND);
            }

            $actModel->load([
                'contract.project', 
                'contract.contractor',
                'completedWorks.workType',
                'completedWorks.materials',
                'completedWorks.user'
            ]);

            $headers = [
                'Наименование работы',
                'Единица измерения', 
                'Количество',
                'Цена за единицу',
                'Сумма',
                'Материалы',
                'Дата выполнения',
                'Исполнитель'
            ];

            $exportData = [];
            $completedWorks = $actModel->completedWorks ?? collect();
            
            foreach ($completedWorks as $work) {
                $materials = '';
                if ($work->materials && $work->materials->isNotEmpty()) {
                    $materials = $work->materials->map(function ($material) {
                        $quantity = $material->pivot->quantity ?? 0;
                        $unit = $material->unit ?? '';
                        return $material->name . ' (' . $quantity . ' ' . $unit . ')';
                    })->join(', ');
                }

                $workTypeName = $work->workType ? $work->workType->name : 'Не указан';
                $executorName = $work->user ? $work->user->name : 'Не указан';
                $completionDate = $work->completion_date ? $work->completion_date->format('d.m.Y') : 'Не указана';

                $exportData[] = [
                    $workTypeName,
                    $work->unit ?? '',
                    $work->quantity ?? 0,
                    $work->unit_price ?? 0,
                    $work->total_amount ?? 0,
                    $materials,
                    $completionDate,
                    $executorName
                ];
            }

            // Если нет работ, добавляем пустую строку
            if (empty($exportData)) {
                $exportData[] = [
                    'Нет выполненных работ',
                    '-',
                    0,
                    0,
                    0,
                    '-',
                    '-',
                    '-'
                ];
            }

            $filename = "act_" . preg_replace('/[^A-Za-z0-9\-_]/', '_', $actModel->act_document_number) . "_" . now()->format('Y-m-d') . ".xlsx";

            return $this->excelExporter->streamDownload($filename, $headers, $exportData);

        } catch (Exception $e) {
            Log::error('Ошибка экспорта Excel акта', [
                'contract_id' => $contract,
                'act_id' => $act,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Ошибка при экспорте в Excel',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить список файлов акта
     */
    public function getFiles(Request $request, int $act): JsonResponse
    {
        try {
            $user = $request->user();
            $organization = $request->attributes->get('current_organization');
            $organizationId = $organization?->id ?? $user?->current_organization_id;

            if (!$organizationId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не определена организация пользователя'
                ], 400);
            }

            // Получаем акт с контрактом напрямую
            $performanceAct = \App\Models\ContractPerformanceAct::with('contract')->find($act);
            
            if (!$performanceAct) {
                return response()->json([
                    'success' => false,
                    'message' => 'Акт не найден'
                ], 404);
            }

            // Проверяем доступ к организации
            if ($performanceAct->contract->organization_id !== $organizationId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Доступ запрещен'
                ], 403);
            }

            // Загружаем файлы с информацией о пользователях
            $performanceAct->load(['files.user']);

            $org = Organization::find($organizationId);
            $disk = $this->fileService->disk($org);

            $files = $performanceAct->files->map(function ($file) use ($disk) {
                $downloadUrl = null;
                try {
                    if ($disk->exists($file->path)) {
                        $downloadUrl = $disk->temporaryUrl($file->path, now()->addHours(1));
                    }
                } catch (Exception $e) {
                    Log::warning('Не удалось создать временный URL для файла', [
                        'file_id' => $file->id,
                        'error' => $e->getMessage()
                    ]);
                }

                return [
                    'id' => $file->id,
                    'name' => $file->original_name,
                    'size' => $file->size,
                    'mime_type' => $file->mime_type,
                    'category' => $file->category,
                    'uploaded_by' => $file->user ? $file->user->name : 'Не указан',
                    'uploaded_at' => $file->created_at->toIso8601String(),
                    'description' => $file->additional_info['description'] ?? null,
                    'download_url' => $downloadUrl
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $files
            ]);

        } catch (Exception $e) {
            Log::error('Ошибка получения файлов акта', [
                'act_id' => $act,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении файлов',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 