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
use Illuminate\Support\Facades\Log;

class ActReportController extends Controller
{
    protected ActReportService $actReportService;

    public function __construct(ActReportService $actReportService)
    {
        $this->actReportService = $actReportService;
    }

    public function index(Request $request): JsonResponse
    {
        try {
            Log::info('ActReportController::index started');
            
            $user = $request->user();
            $organizationId = $user->organization_id ?? $user->current_organization_id;
            
            Log::info('ActReportController::index org_id', [
                'org_id' => $organizationId,
                'user_org_id' => $user->organization_id,
                'user_current_org_id' => $user->current_organization_id
            ]);
            
            if (!$organizationId) {
                return response()->json([
                    'error' => 'Не определена организация пользователя'
                ], 400);
            }
            
            $filters = $request->only([
                'performance_act_id',
                'format',
                'date_from',
                'date_to'
            ]);

            $reports = $this->actReportService->getReportsByOrganization($organizationId, $filters);
            Log::info('ActReportController::index reports count', ['count' => $reports->count()]);

            return response()->json([
                'data' => new ActReportCollection($reports),
                'message' => 'Отчеты актов получены успешно'
            ]);
        } catch (\Exception $e) {
            Log::error('ActReportController::index error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            return response()->json([
                'error' => 'Ошибка при получении отчетов',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function store(CreateActReportRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $organizationId = $user->organization_id ?? $user->current_organization_id;
            
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
        try {
            Log::info('ActReportController::show started', ['report_id' => $actReport->id]);
            
            return response()->json([
                'data' => new ActReportResource($actReport->load(['performanceAct.contract.project', 'performanceAct.contract.contractor'])),
                'message' => 'Отчет акта получен успешно'
            ]);
        } catch (\Exception $e) {
            Log::error('ActReportController::show error', [
                'message' => $e->getMessage(),
                'report_id' => $actReport->id
            ]);
            
            return response()->json([
                'error' => 'Ошибка при получении отчета',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function download(ActReport $actReport): JsonResponse
    {
        try {
            Log::info('ActReportController::download started', ['report_id' => $actReport->id]);
            
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
        } catch (\Exception $e) {
            Log::error('ActReportController::download error', [
                'message' => $e->getMessage(),
                'report_id' => $actReport->id
            ]);
            
            return response()->json([
                'error' => 'Ошибка при получении ссылки',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function regenerate(ActReport $actReport): JsonResponse
    {
        try {
            Log::info('ActReportController::regenerate started', ['report_id' => $actReport->id]);
            
            $report = $this->actReportService->regenerateReport($actReport);

            return response()->json([
                'data' => new ActReportResource($report),
                'message' => 'Отчет акта перегенерирован успешно'
            ]);
        } catch (\Exception $e) {
            Log::error('ActReportController::regenerate error', [
                'message' => $e->getMessage(),
                'report_id' => $actReport->id
            ]);
            
            return response()->json([
                'error' => 'Ошибка при перегенерации отчета',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(ActReport $actReport): JsonResponse
    {
        try {
            Log::info('ActReportController::destroy started', ['report_id' => $actReport->id]);
            
            $actReport->delete();

            return response()->json([
                'message' => 'Отчет акта удален успешно'
            ]);
        } catch (\Exception $e) {
            Log::error('ActReportController::destroy error', [
                'message' => $e->getMessage(),
                'report_id' => $actReport->id
            ]);
            
            return response()->json([
                'error' => 'Ошибка при удалении отчета',
                'message' => $e->getMessage()
            ], 500);
        }
    }
} 