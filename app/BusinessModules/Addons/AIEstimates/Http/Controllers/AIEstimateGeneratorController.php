<?php

namespace App\BusinessModules\Addons\AIEstimates\Http\Controllers;

use App\BusinessModules\Addons\AIEstimates\DTOs\AIEstimateRequestDTO;
use App\BusinessModules\Addons\AIEstimates\DTOs\FeedbackDTO;
use App\BusinessModules\Addons\AIEstimates\Http\Requests\GenerateEstimateRequest;
use App\BusinessModules\Addons\AIEstimates\Http\Requests\ProvideFeedbackRequest;
use App\BusinessModules\Addons\AIEstimates\Http\Resources\AIGenerationHistoryResource;
use App\BusinessModules\Addons\AIEstimates\Http\Resources\GeneratedEstimateDraftResource;
use App\BusinessModules\Addons\AIEstimates\Models\AIGenerationHistory;
use App\BusinessModules\Addons\AIEstimates\Services\AIEstimateGenerationService;
use App\BusinessModules\Addons\AIEstimates\Services\Cache\CacheService;
use App\BusinessModules\Addons\AIEstimates\Services\Export\AIEstimateExportService;
use App\BusinessModules\Addons\AIEstimates\Services\FeedbackCollectorService;
use App\BusinessModules\Addons\AIEstimates\Services\UsageLimitService;
use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AIEstimateGeneratorController extends Controller
{
    public function __construct(
        protected AIEstimateGenerationService $generationService,
        protected UsageLimitService $usageLimitService,
        protected FeedbackCollectorService $feedbackCollectorService,
        protected AIEstimateExportService $exportService,
        protected CacheService $cacheService,
    ) {}

    public function generate(GenerateEstimateRequest $request, Project $project): JsonResponse
    {
        try {
            $user = $request->user();

            // Создаем DTO из запроса
            $requestDTO = AIEstimateRequestDTO::fromRequest(
                $request->validated(),
                $project->id,
                $user->current_organization_id,
                $user->id
            );

            // Генерируем смету
            $response = $this->generationService->generate($requestDTO);

            return response()->json([
                'success' => true,
                'data' => new GeneratedEstimateDraftResource((object) array_merge(
                    $response->toArray(),
                    ['generation_id' => $response->generationId]
                )),
                'message' => 'Смета успешно сгенерирована',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка генерации сметы: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function history(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();

        $history = AIGenerationHistory::forOrganization($user->current_organization_id)
            ->forProject($project->id)
            ->with(['user', 'feedback'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => AIGenerationHistoryResource::collection($history),
            'meta' => [
                'current_page' => $history->currentPage(),
                'last_page' => $history->lastPage(),
                'per_page' => $history->perPage(),
                'total' => $history->total(),
            ],
        ]);
    }

    public function show(Request $request, Project $project, AIGenerationHistory $generation): JsonResponse
    {
        // Проверка доступа
        if ($generation->organization_id !== $request->user()->current_organization_id) {
            return response()->json([
                'success' => false,
                'message' => 'У вас нет доступа к этой генерации',
            ], 403);
        }

        $generation->load(['user', 'feedback']);

        return response()->json([
            'success' => true,
            'data' => new AIGenerationHistoryResource($generation),
        ]);
    }

    public function provideFeedback(
        ProvideFeedbackRequest $request,
        Project $project,
        AIGenerationHistory $generation
    ): JsonResponse {
        try {
            // Проверка доступа
            if ($generation->organization_id !== $request->user()->current_organization_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'У вас нет доступа к этой генерации',
                ], 403);
            }

            $feedbackDTO = FeedbackDTO::fromRequest($request->validated());
            $feedback = $this->feedbackCollectorService->collectFeedback($generation->id, $feedbackDTO);

            return response()->json([
                'success' => true,
                'data' => [
                    'feedback_id' => $feedback->id,
                    'acceptance_rate' => $feedback->acceptance_rate,
                ],
                'message' => 'Обратная связь успешно сохранена',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка сохранения обратной связи: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function export(Request $request, Project $project, AIGenerationHistory $generation): JsonResponse
    {
        try {
            // Проверка доступа
            if ($generation->organization_id !== $request->user()->current_organization_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'У вас нет доступа к этой генерации',
                ], 403);
            }

            $format = $request->input('format', 'pdf');

            if (!$this->exportService->isFormatSupported($format)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неподдерживаемый формат экспорта',
                ], 400);
            }

            $exportResult = $this->exportService->export($generation, $format);

            return response()->json([
                'success' => true,
                'data' => $exportResult,
                'message' => 'Смета успешно экспортирована',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка экспорта сметы: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function usageLimits(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();
        $limitInfo = $this->usageLimitService->getLimitInfo($user->current_organization_id);

        return response()->json([
            'success' => true,
            'data' => $limitInfo,
        ]);
    }

    public function clearCache(Request $request, Project $project): JsonResponse
    {
        try {
            $user = $request->user();
            $this->cacheService->clearProjectCache($user->current_organization_id, $project->id);

            return response()->json([
                'success' => true,
                'message' => 'Кеш успешно очищен',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка очистки кеша: ' . $e->getMessage(),
            ], 500);
        }
    }
}
