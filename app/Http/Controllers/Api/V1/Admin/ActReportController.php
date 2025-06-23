<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActReportService;
use App\Http\Requests\Api\V1\Admin\ActReport\CreateActReportRequest;
use App\Http\Resources\Api\V1\Admin\ActReport\ActReportResource;
use App\Http\Resources\Api\V1\Admin\ActReport\ActReportCollection;
use App\Models\ActReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class ActReportController extends Controller
{
    protected ActReportService $actReportService;

    public function __construct(ActReportService $actReportService)
    {
        $this->actReportService = $actReportService;
        $this->middleware('can:view-act-reports')->only(['index', 'show']);
        $this->middleware('can:create-act-reports')->only(['store']);
        $this->middleware('can:delete-act-reports')->only(['destroy']);
    }

    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->user()->organization_id;
        
        $filters = $request->only([
            'performance_act_id',
            'format',
            'date_from',
            'date_to'
        ]);

        $reports = $this->actReportService->getReportsByOrganization($organizationId, $filters);

        return response()->json([
            'data' => new ActReportCollection($reports),
            'message' => 'Отчеты актов получены успешно'
        ]);
    }

    public function store(CreateActReportRequest $request): JsonResponse
    {
        try {
            $organizationId = $request->user()->organization_id;
            
            if (!$organizationId) {
                return response()->json([
                    'error' => 'Не определена организация пользователя'
                ], 400);
            }
            
            $report = $this->actReportService->createReport(
                $organizationId,
                $request->performance_act_id,
                $request->format,
                $request->title
            );

            return response()->json([
                'data' => new ActReportResource($report),
                'message' => 'Отчет акта создан успешно'
            ], 201);
            
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Ошибка при создании отчета',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function show(ActReport $actReport): JsonResponse
    {
        $this->authorize('view', $actReport);

        return response()->json([
            'data' => new ActReportResource($actReport->load(['performanceAct.contract.project', 'performanceAct.contract.contractor'])),
            'message' => 'Отчет акта получен успешно'
        ]);
    }

    public function download(ActReport $actReport): JsonResponse
    {
        $this->authorize('view', $actReport);

        if ($actReport->isExpired()) {
            return response()->json([
                'error' => 'Срок действия отчета истек'
            ], 410);
        }

        $downloadUrl = $actReport->getDownloadUrl();
        
        if (!$downloadUrl) {
            return response()->json([
                'error' => 'Файл отчета не найден'
            ], 404);
        }

        return response()->json([
            'data' => [
                'download_url' => $downloadUrl,
                'filename' => basename($actReport->file_path),
                'file_size' => $actReport->getFileSizeFormatted(),
                'expires_at' => $actReport->expires_at->format('Y-m-d H:i:s')
            ],
            'message' => 'Ссылка на скачивание получена успешно'
        ]);
    }

    public function regenerate(ActReport $actReport): JsonResponse
    {
        $this->authorize('update', $actReport);

        $report = $this->actReportService->regenerateReport($actReport);

        return response()->json([
            'data' => new ActReportResource($report),
            'message' => 'Отчет акта перегенерирован успешно'
        ]);
    }

    public function destroy(ActReport $actReport): JsonResponse
    {
        $this->authorize('delete', $actReport);

        $actReport->delete();

        return response()->json([
            'message' => 'Отчет акта удален успешно'
        ]);
    }
} 