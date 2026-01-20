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
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use function trans_message;

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
            /** @var \App\Models\User $user */
            $user = Auth::user();

            // Создаем DTO из запроса
            /** @var array<string, mixed> $validated */
            $validated = $request->validated();
            /** @phpstan-ignore-next-line */
            $requestDTO = AIEstimateRequestDTO::fromRequest(
                $validated,
                $project->id,
                $user->current_organization_id,
                $user->id
            );

            // Генерируем смету
            $response = $this->generationService->generate($requestDTO);

            $data = new GeneratedEstimateDraftResource((object) array_merge(
                $response->toArray(),
                ['generation_id' => $response->generationId]
            ));

            return AdminResponse::success($data, trans_message('ai_estimates.generation_success'));

        } catch (\Exception $e) {
            return AdminResponse::error(trans_message('ai_estimates.generation_error'), 500);
        }
    }

    public function history(Request $request, Project $project): JsonResponse
    {
        try {
            /** @var \App\Models\User $user */
            $user = Auth::user();

            /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator<AIGenerationHistory> $history */
            /** @phpstan-ignore-next-line */
            $history = AIGenerationHistory::forOrganization($user->current_organization_id)
                ->forProject($project->id)
                ->with(['user', 'feedback'])
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            $data = [
                'data' => AIGenerationHistoryResource::collection($history),
                'meta' => [
                    'current_page' => $history->currentPage(),
                    'last_page' => $history->lastPage(),
                    'per_page' => $history->perPage(),
                    'total' => $history->total(),
                ],
            ];

            return AdminResponse::success($data);

        } catch (\Exception $e) {
            return AdminResponse::error(trans_message('ai_estimates.history_load_error'), 500);
        }
    }

    public function show(Request $request, Project $project, AIGenerationHistory $generation): JsonResponse
    {
        try {
            /** @var \App\Models\User $user */
            $user = Auth::user();
            
            // Проверка доступа
            /** @phpstan-ignore-next-line */
            if ($generation->organization_id !== $user->current_organization_id) {
                return AdminResponse::error(trans_message('ai_estimates.access_denied'), 403);
            }

            /** @var AIGenerationHistory $generation */
            /** @phpstan-ignore-next-line */
            $generation->load(['user', 'feedback']);

            return AdminResponse::success(new AIGenerationHistoryResource($generation));

        } catch (\Exception $e) {
            return AdminResponse::error(trans_message('ai_estimates.generation_data_error'), 500);
        }
    }

    public function provideFeedback(
        ProvideFeedbackRequest $request,
        Project $project,
        AIGenerationHistory $generation
    ): JsonResponse {
        try {
            /** @var \App\Models\User $user */
            $user = Auth::user();
            
            // Проверка доступа
            if ($generation->organization_id !== $user->current_organization_id) {
                return AdminResponse::error(trans_message('ai_estimates.access_denied'), 403);
            }

            /** @var array<string, mixed> $validated */
            $validated = $request->validated();
            $feedbackDTO = FeedbackDTO::fromRequest($validated);
            /** @var \App\BusinessModules\Addons\AIEstimates\Models\AIGenerationFeedback $feedback */
            $feedback = $this->feedbackCollectorService->collectFeedback($generation->id, $feedbackDTO);

            $data = [
                'feedback_id' => $feedback->id,
                'acceptance_rate' => $feedback->acceptance_rate,
            ];

            return AdminResponse::success($data, trans_message('ai_estimates.feedback_success'));

        } catch (\Exception $e) {
            return AdminResponse::error(trans_message('ai_estimates.feedback_error'), 500);
        }
    }

    public function export(Request $request, Project $project, AIGenerationHistory $generation): JsonResponse
    {
        try {
            /** @var \App\Models\User $user */
            $user = Auth::user();
            
            // Проверка доступа
            if ($generation->organization_id !== $user->current_organization_id) {
                return AdminResponse::error(trans_message('ai_estimates.access_denied'), 403);
            }

            $format = $request->input('format', 'pdf');

            if (!$this->exportService->isFormatSupported($format)) {
                return AdminResponse::error(trans_message('ai_estimates.export_format_unsupported'), 400);
            }

            $exportResult = $this->exportService->export($generation, $format);

            return AdminResponse::success($exportResult, trans_message('ai_estimates.export_success'));

        } catch (\Exception $e) {
            return AdminResponse::error(trans_message('ai_estimates.export_error'), 500);
        }
    }

    public function usageLimits(Request $request, Project $project): JsonResponse
    {
        try {
            /** @var \App\Models\User $user */
            $user = Auth::user();
            $limitInfo = $this->usageLimitService->getLimitInfo($user->current_organization_id);

            return AdminResponse::success($limitInfo);

        } catch (\Exception $e) {
            return AdminResponse::error(trans_message('ai_estimates.limits_error'), 500);
        }
    }

    public function clearCache(Request $request, Project $project): JsonResponse
    {
        try {
            /** @var \App\Models\User $user */
            $user = Auth::user();
            $this->cacheService->clearProjectCache($user->current_organization_id, $project->id);

            return AdminResponse::success(null, trans_message('ai_estimates.cache_cleared'));

        } catch (\Exception $e) {
            return AdminResponse::error(trans_message('ai_estimates.cache_clear_error'), 500);
        }
    }
}
