<?php

namespace App\BusinessModules\Addons\AIEstimates\Services;

use App\BusinessModules\Addons\AIEstimates\DTOs\AIEstimateRequestDTO;
use App\BusinessModules\Addons\AIEstimates\DTOs\AIEstimateResponseDTO;
use App\BusinessModules\Addons\AIEstimates\Enums\GenerationStatus;
use App\BusinessModules\Addons\AIEstimates\Models\AIGenerationHistory;
use App\BusinessModules\Addons\AIEstimates\Services\Cache\CacheKeyGenerator;
use App\BusinessModules\Addons\AIEstimates\Services\Cache\CacheService;
use App\BusinessModules\Addons\AIEstimates\Services\FileProcessing\FileParserService;
use App\BusinessModules\Addons\AIEstimates\Services\YandexGPT\PromptBuilder;
use App\BusinessModules\Addons\AIEstimates\Services\YandexGPT\YandexGPTClient;
use App\BusinessModules\Addons\AIEstimates\AIEstimatesModule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AIEstimateGenerationService
{
    public function __construct(
        protected YandexGPTClient $yandexGPTClient,
        protected PromptBuilder $promptBuilder,
        protected CacheService $cacheService,
        protected CacheKeyGenerator $cacheKeyGenerator,
        protected FileParserService $fileParserService,
        protected CatalogMatchingService $catalogMatchingService,
        protected ProjectHistoryAnalysisService $historyAnalysisService,
        protected EstimateBuilderService $estimateBuilderService,
        protected UsageLimitService $usageLimitService,
    ) {}

    public function generate(AIEstimateRequestDTO $request): AIEstimateResponseDTO
    {
        $startTime = microtime(true);

        // 1. Проверить лимиты
        if (!$this->usageLimitService->canGenerate($request->organizationId)) {
            throw new \Exception('Превышен лимит генераций смет на текущий месяц');
        }

        // 2. Получить настройки модуля
        $module = app(AIEstimatesModule::class);
        $settings = $module->getSettings($request->organizationId);

        // 3. Проверить кеш
        $cacheKey = null;
        if ($this->cacheKeyGenerator->shouldCache($request) && $this->cacheService->isEnabled()) {
            $cacheKey = $this->cacheKeyGenerator->generate($request);
            $cached = $this->cacheService->getCached($cacheKey);

            if ($cached) {
                Log::info('[AIEstimateGenerationService] Returning cached result', [
                    'organization_id' => $request->organizationId,
                    'project_id' => $request->projectId,
                ]);

                return AIEstimateResponseDTO::fromGenerationResult(
                    $cached['generation_id'],
                    $cached['estimate_data'],
                    $cached['tokens_used'],
                    $cached['processing_time']
                );
            }
        }

        // 4. Создать запись в БД
        $generation = $this->createGenerationRecord($request);

        try {
            // 5. Обработать файлы (если есть)
            $ocrContext = [];
            if ($request->hasFiles()) {
                $generation->update(['status' => GenerationStatus::PROCESSING]);
                $ocrResults = $this->fileParserService->parseFiles($request->files);
                $ocrContext = $this->promptBuilder->buildContextFromOCR($ocrResults);
                
                // Сохраняем результаты OCR
                $generation->update(['ocr_results' => $ocrResults]);
            }

            // 6. Анализ похожих проектов
            $historyContext = [];
            if ($settings['ai_settings']['use_project_history'] ?? true) {
                $similarProjects = $this->historyAnalysisService->findSimilarProjects(
                    $request->organizationId,
                    $request->area,
                    $request->buildingType
                );
                $historyContext = $this->promptBuilder->buildContextFromHistory($similarProjects);
            }

            // 7. Объединяем контекст
            $context = array_merge($ocrContext, $historyContext);

            // 8. Генерация через YandexGPT
            $systemPrompt = $this->promptBuilder->buildSystemPrompt();
            $userPrompt = $this->promptBuilder->buildUserPrompt($request, $context);

            $aiResult = $this->yandexGPTClient->generateEstimate($userPrompt, $systemPrompt);

            // 9. Маппинг на каталог позиций
            $allItems = $this->extractAllItemsFromAIResponse($aiResult['estimate_data']);
            $matchedPositions = $this->catalogMatchingService->matchAIItemsToCatalog(
                $allItems,
                $request->organizationId
            );

            // 10. Сборка draft сметы
            $estimateDraft = $this->estimateBuilderService->buildDraft(
                $aiResult['estimate_data'],
                $matchedPositions,
                $request->projectId
            );

            // 11. Обновление записи
            $processingTime = round((microtime(true) - $startTime) * 1000);
            
            $generation->update([
                'status' => GenerationStatus::COMPLETED,
                'ai_response' => $aiResult['estimate_data'],
                'matched_positions' => $matchedPositions,
                'generated_estimate_draft' => $estimateDraft,
                'confidence_score' => $estimateDraft['average_confidence'],
                'tokens_used' => $aiResult['tokens_used'],
                'cost' => $this->calculateCost($aiResult['tokens_used']),
                'processing_time_ms' => $processingTime,
            ]);

            // 12. Сохранить в кеш
            if ($cacheKey && $this->cacheService->isEnabled()) {
                $this->cacheService->storeCached($cacheKey, [
                    'generation_id' => $generation->id,
                    'estimate_data' => $estimateDraft,
                    'tokens_used' => $aiResult['tokens_used'],
                    'processing_time' => $processingTime / 1000,
                ]);
            }

            // 13. Вернуть результат
            return AIEstimateResponseDTO::fromGenerationResult(
                $generation->id,
                $estimateDraft,
                $aiResult['tokens_used'],
                $processingTime / 1000
            );

        } catch (\Exception $e) {
            Log::error('[AIEstimateGenerationService] Generation failed', [
                'generation_id' => $generation->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $generation->update([
                'status' => GenerationStatus::FAILED,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    protected function createGenerationRecord(AIEstimateRequestDTO $request): AIGenerationHistory
    {
        return AIGenerationHistory::create([
            'organization_id' => $request->organizationId,
            'project_id' => $request->projectId,
            'user_id' => $request->userId,
            'input_description' => $request->description,
            'input_parameters' => [
                'area' => $request->area,
                'building_type' => $request->buildingType,
                'region' => $request->region,
            ],
            'uploaded_files' => $request->hasFiles() ? 
                collect($request->files)->map(fn($f) => $f->getClientOriginalName())->toArray() : 
                null,
            'status' => GenerationStatus::PENDING,
        ]);
    }

    protected function extractAllItemsFromAIResponse(array $aiResponse): array
    {
        $allItems = [];

        foreach ($aiResponse['sections'] ?? [] as $section) {
            foreach ($section['items'] ?? [] as $item) {
                $allItems[] = $item;
            }
        }

        return $allItems;
    }

    protected function calculateCost(int $tokensUsed): float
    {
        // YandexGPT 5 Pro: ~0.40₽ за 1000 токенов
        $costPer1000Tokens = 0.40;
        return round(($tokensUsed / 1000) * $costPer1000Tokens, 2);
    }
}
