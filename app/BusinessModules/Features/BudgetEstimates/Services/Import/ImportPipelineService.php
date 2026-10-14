<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningRecorder;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Classification\ItemClassificationService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportFormatRegistry;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportStructureResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportValidationResult;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use App\Models\ImportSession;
use App\Models\MeasurementUnit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportPipelineService
{
    private const BATCH_SIZE = 100;

    private const GRAND_SMETA_HANDLER_SLUG = 'grandsmeta';

    private array $unitCache = [];

    private array $workUnitKeywords = [
        'чел-ч', 'маш-ч', 'смн', 'чел-дн', 'маш-смн', 'рейс', 'усл', 'этап', 'час',
    ];

    public function __construct(
        private FileStorageService $fileStorage,
        private ImportFormatRegistry $runtimeRegistry,
        private ImportRowMapper $rowMapper,
        private EstimateService $estimateService,
        private ItemClassificationService $classifier,
        private NormativeMatchingService $matcher,
        private SemanticMatchingService $semanticMatcher,
        private SubItemGroupingService $subItemGrouper,
        private \App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService $calculationService,
        private MaterialMatchingService $materialMatcher,
        private EstimateImportFinancialSettingsResolver $financialSettingsResolver,
        private EstimateGenerationLearningRecorder $estimateGenerationLearningRecorder
    ) {}

    private function updateProgress(ImportSession $session, int $progress, string $message): void
    {
        // 1. Update Cache for real-time tracking (bypasses DB transactions)
        Cache::put("import_session_progress_{$session->id}", $progress, 3600);

        // 2. Update Database (will be committed later)
        $fresh = $session->fresh();
        $session->update([
            'stats' => array_merge($fresh->stats ?? [], [
                'progress' => $progress,
                'message' => $message,
            ]),
        ]);
    }

    public function run(ImportSession $session): void
    {
        $this->fileStorage->withLocalCopy(
            $session,
            function (string $filePath) use ($session): void {
                $this->runWithLocalCopy($session, $filePath);
            },
        );
    }

    private function runWithLocalCopy(ImportSession $session, string $filePath): void
    {
        $this->subItemState = [];
        $runToken = $session->options['run_token'] ?? null;
        Log::info("[ImportPipeline] Started for session {$session->id}");

        DB::transaction(function () use ($session, $runToken): void {
            $lockedSession = ImportSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $this->assertWorkerClaim($lockedSession, $runToken);
            $session->update([
                'status' => 'parsing',
                'stats' => array_merge($lockedSession->stats ?? [], ['progress' => 5, 'message' => trans_message('estimate.import_processing_started')]),
            ]);
        });

        $handlerSlug = (string) ($session->options['format_handler'] ?? '');
        if ($handlerSlug === '') {
            throw new \RuntimeException(trans_message('estimate.import_format_not_detected'));
        }

        $handler = $this->runtimeRegistry->bySlug($handlerSlug);
        $structureResult = $this->structureFromSession($handler->slug(), $session)
            ?? $handler->detectStructure($session, $filePath);

        DB::transaction(function () use ($session, $runToken, $handler, $structureResult): void {
            $lockedSession = ImportSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $this->assertWorkerClaim($lockedSession, $runToken);
            $sessionOptions = $lockedSession->options ?? [];
            $sessionOptions['format_handler'] = $handler->slug();
            $sessionOptions['structure'] = $structureResult->toArray();
            $session->update(['options' => $sessionOptions]);
        });
        $session = $session->fresh();

        $preview = $handler->preview($session, $filePath, $structureResult);
        $validation = $handler->validate($session, $preview);
        $totalRows = (int) ($preview->summary['rows_count'] ?? (count($preview->sections) + count($preview->items)));

        // 1. Оцениваем общее количество строк для прогресса

        $session->update([
            'stats' => array_merge($session->fresh()->stats ?? [], [
                'progress' => 10,
                'total_rows' => $totalRows,
                'validation' => $validation->toArray(),
                'message' => trans_message('estimate.import_processing_file'),
            ]),
        ]);

        if (($session->options['validate_only'] ?? false) === true) {
            DB::transaction(function () use ($session, $runToken, $totalRows, $validation): void {
                $lockedSession = ImportSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
                $this->assertWorkerClaim($lockedSession, $runToken);
                $session->update([
                    'status' => 'validated',
                    'stats' => array_merge($lockedSession->stats ?? [], [
                        'progress' => 100,
                        'result' => [
                            'processed_rows' => $totalRows,
                            'sections_created' => 0,
                            'items_created' => 0,
                            'total_rows' => $totalRows,
                            'validation' => $validation->toArray(),
                        ],
                        'message' => trans_message('estimate.import_validated'),
                    ]),
                ]);
            });

            Cache::put("import_session_progress_{$session->id}", 100, 3600);

            return;
        }

        if (! $validation->isValid()) {
            $message = $this->validationFailureMessage($validation);
            $session->update([
                'stats' => array_merge($session->fresh()->stats ?? [], [
                    'message' => $message,
                ]),
            ]);

            throw new \RuntimeException($message);
        }

        $stream = $handler->streamRows($session, $filePath, $structureResult);

        $stats = [
            'processed_rows' => 0,
            'sections_created' => 0,
            'items_created' => 0,
            'total_rows' => $totalRows,
        ];

        DB::beginTransaction();
        try {
            $lockedSession = ImportSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $this->assertWorkerClaim($lockedSession, $runToken);
            $estimate = $this->resolveEstimate($session);
            $this->processStream($stream, $estimate, $stats, $session);
            $removedEmptySections = $this->pruneEmptyLeafSections($estimate, $handler->slug());
            if ($removedEmptySections > 0) {
                $stats['sections_created'] = max(0, $stats['sections_created'] - $removedEmptySections);
                $stats['empty_sections_removed'] = $removedEmptySections;
            }

            if (method_exists($handler, 'getFooterData')) {
                $footerData = $handler->getFooterData();
                if (! empty($footerData)) {
                    Log::info('[ImportPipeline] Found footer data', [
                        'session_id' => $session->id,
                        'fields_count' => count($footerData),
                    ]);

                    // Update estimate with footer values
                    $meta = $estimate->metadata ?? [];
                    $meta['footer'] = $footerData;
                    $meta['import_source_charges_preserved'] = true;

                    $estimate->metadata = $meta;
                    $estimate->save();
                    $this->persistFooterCharges($estimate, $footerData);
                }
            }

            // Update Totals with proper markup distribution
            $this->updateProgress($session, 90, trans_message('estimate.import_recalculating_totals'));
            $this->calculationService->recalculateAll($estimate);

            $session->update([
                'status' => 'completed',
                'stats' => array_merge($session->fresh()->stats ?? [], [
                    'progress' => 100,
                    'estimate_id' => $estimate->id,
                    'result' => $stats,
                    'message' => trans_message('estimate.import_completed'),
                ]),
            ]);

            DB::commit();
            Cache::put("import_session_progress_{$session->id}", 100, 3600);

            try {
                $freshEstimate = $estimate->fresh() ?? $estimate;
                $stats['learning_examples_created'] = $this->estimateGenerationLearningRecorder
                    ->recordImportedEstimate($freshEstimate, $session->fresh());
            } catch (\Throwable $e) {
                Log::warning('[ImportPipeline] Estimate generation learning failed', [
                    'session_id' => $session->id,
                    'estimate_id' => $estimate->id,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info("[ImportPipeline] Finished for session {$session->id}", $stats);

            $session->update([
                'stats' => array_merge($session->fresh()->stats ?? [], [
                    'result' => $stats,
                ]),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[ImportPipeline] Error: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
            throw $e;
        }
    }

    private function structureFromSession(string $formatSlug, ImportSession $session): ?ImportStructureResult
    {
        $structure = $session->options['structure'] ?? null;
        if (! is_array($structure)) {
            return null;
        }

        $columnMapping = ImportStructureResult::columnMappingFromArray($structure);
        $detectedColumns = $structure['detected_columns'] ?? [];
        if ((! is_array($detectedColumns) || $detectedColumns === []) && $columnMapping !== []) {
            $detectedColumns = ImportStructureResult::detectedColumnsFromMapping($columnMapping);
        }

        return new ImportStructureResult(
            formatSlug: $formatSlug,
            headerRow: isset($structure['header_row']) ? (int) $structure['header_row'] : null,
            columnMapping: $columnMapping,
            detectedColumns: is_array($detectedColumns) ? $detectedColumns : [],
            rawHeaders: $structure['raw_headers'] ?? [],
            sampleRows: $structure['sample_rows'] ?? [],
            headerCandidates: $structure['header_candidates'] ?? [],
            rowStyles: $structure['row_styles'] ?? [],
            warnings: $structure['warnings'] ?? [],
            metadata: $structure['metadata'] ?? [],
            aiMappingApplied: (bool) ($structure['ai_mapping_applied'] ?? false),
        );
    }

    private function assertWorkerClaim(ImportSession $current, ?string $runToken): void
    {
        if (! $current->isActive()
            || ($current->options['run_token'] ?? null) !== $runToken
            || isset($current->stats['estimate_id'])
            || (isset($current->stats['worker_deadline_at']) && now()->greaterThanOrEqualTo(\Illuminate\Support\Carbon::parse($current->stats['worker_deadline_at'])))) {
            throw new \RuntimeException('estimate_import_worker_claim_expired');
        }
    }

    private function persistFooterCharges(Estimate $estimate, array $footer): void
    {
        foreach (['overhead_cost', 'profit_cost'] as $key) {
            if ((float) ($footer[$key] ?? 0) == 0
                && empty($footer[$key.'_declared']) && ! array_key_exists('global_'.$key, $footer)) {
                unset($footer[$key]);
            }
        }
        if (! array_key_exists('overhead_cost', $footer) && ! array_key_exists('profit_cost', $footer)) {
            return;
        }
        $items = EstimateItem::query()
            ->where('estimate_id', $estimate->id)
            ->whereNull('parent_work_id')
            ->where('is_not_accounted', false)
            ->orderBy('id')
            ->get();
        $components = [];
        foreach ($items as $item) {
            $components[$item->id] = [
                'current_total_amount' => $item->current_total_amount,
                'direct_costs' => $item->direct_costs,
                'labor_cost' => $item->labor_cost,
                'overhead_amount' => $item->overhead_amount,
                'profit_amount' => $item->profit_amount,
                'has_source_overhead_amount' => $item->metadata['import_has_source_overhead'] ?? false,
                'has_source_profit_amount' => $item->metadata['import_has_source_profit'] ?? false,
            ];
        }
        $distributed = (new ImportedEstimateChargeDistributor)->distribute($components, $footer);
        foreach ($items as $item) {
            $values = $distributed[$item->id];
            if (! isset($values['footer_components'])) {
                continue;
            }
            $metadata = $item->metadata ?? [];
            $metadata['import_source_components_preserved'] = true;
            $metadata['import_footer_charges'] = $values['footer_components'];
            $item->update([
                'current_total_amount' => $values['current_total_amount'],
                'total_amount' => $values['current_total_amount'],
                'overhead_amount' => $values['overhead_amount'],
                'profit_amount' => $values['profit_amount'],
                'metadata' => $metadata,
            ]);
        }
    }

    private function validationFailureMessage(ImportValidationResult $validation): string
    {
        $reason = $this->validationMessage($validation->errors[0] ?? null);

        if ($reason === '') {
            return trans_message('estimate.import_validation_failed');
        }

        return trans_message('estimate.import_validation_failed_with_reason', ['reason' => $reason]);
    }

    private function validationMessage(mixed $message): string
    {
        if (is_array($message)) {
            $message = $message['message'] ?? $message['key'] ?? reset($message);
        }

        if (! is_string($message)) {
            return '';
        }

        $message = trim($message);
        if ($message === '') {
            return '';
        }

        return str_starts_with($message, 'estimate.') ? trans_message($message) : $message;
    }

    private function resolveEstimate(ImportSession $session): Estimate
    {
        $settings = $session->options['estimate_settings'] ?? [];
        $financialSettings = $this->financialSettingsResolver->resolve($settings);

        return $this->estimateService->create([
            'organization_id' => $session->organization_id,
            'project_id' => $settings['project_id'] ?? null,
            'name' => $settings['name'] ?? $session->file_name,
            'type' => $settings['type'] ?? 'local',
            'calculation_method' => $settings['calculation_method'] ?? 'resource',
            'estimate_date' => $settings['estimate_date'] ?? now()->format('Y-m-d'),
            'contract_id' => $settings['contract_id'] ?? null,
            'vat_rate' => $financialSettings['vat_rate'],
            'overhead_rate' => $financialSettings['overhead_rate'],
            'profit_rate' => $financialSettings['profit_rate'],
            'metadata' => [
                'import_financial_mode' => $settings['financial_mode'] ?? 'plain',
                'import_execution_user_id' => (int) ($session->options['execution_user_id'] ?? $session->user_id),
                'import_source_user_id' => (int) $session->user_id,
            ],
        ]);
    }

    private array $subItemState = [];

    private function processStream(\Generator $stream, Estimate $estimate, array &$stats, ImportSession $session): void
    {
        $batchDTOs = [];
        $sectionMap = []; // path -> section_id (e.g. "1" -> 101, "1.1" -> 102)
        $lastSectionId = null; // For items without explicit section path?
        $rowPolicy = new ImportRowPolicy($this->rowMapper);

        foreach ($stream as $rowDTO) {
            if (! $rowDTO instanceof EstimateImportRowDTO) {
                $rowDTO = EstimateImportRowDTO::fromArray((array) $rowDTO);
            }

            if (!$rowPolicy->shouldImport($rowDTO)) {
                continue;
            }

            if (! $rowDTO->isSection) {
                $warnings = array_values(array_unique($rowDTO->warnings));
                if ($warnings !== []) {
                    $stats['row_warnings'][] = [
                        'row_number' => $rowDTO->rowNumber,
                        'worksheet_index' => $rowDTO->worksheetIndex,
                        'worksheet_name' => $rowDTO->worksheetName,
                        'warnings' => $warnings,
                    ];
                }
            }

            if ($rowDTO->isSection) {
                // If we have items in batch, process and save them first to maintain order
                if (! empty($batchDTOs)) {
                    $this->processAndInsertBatch($batchDTOs, $estimate, $stats, $session);
                    $batchDTOs = [];
                }

                // Сбрасываем подпункты, так как начался новый раздел
                $this->subItemState = [];

                // Save Section immediately (needed for ID)
                $section = $this->saveSection($rowDTO, $estimate->id, $sectionMap);
                $lastSectionId = $section->id;
                $stats['sections_created']++;
            } else {
                // Collect row but assign closest section
                $rowDTO->sectionPath = $rowDTO->sectionPath ?: null;
                $batchDTOs[] = [
                    'dto' => $rowDTO,
                    'section_id' => $this->resolveSectionId($rowDTO, $sectionMap, $lastSectionId),
                ];

                if (count($batchDTOs) >= self::BATCH_SIZE) {
                    $this->processAndInsertBatch($batchDTOs, $estimate, $stats, $session);
                    $batchDTOs = [];
                }
            }

            $stats['processed_rows']++;

            // Обновляем прогресс каждые 10 строк
            if ($stats['processed_rows'] % 10 === 0) {
                $totalRows = $stats['total_rows'] ?? 0;
                // Распределяем диапазон от 10% до 88%
                $progress = $totalRows > 0
                    ? (int) (10 + min(78, (($stats['processed_rows'] / $totalRows) * 78)))
                    : min(88, 10 + (int) ($stats['processed_rows'] / 5));

                $this->updateProgress(
                    $session,
                    $progress,
                    trans_message('estimate.import_processed_rows', [
                        'current' => $stats['processed_rows'],
                        'total' => $totalRows,
                    ])
                );
                $session->stats = array_merge($session->fresh()->stats ?? [], ['processed_rows' => $stats['processed_rows']]);
            }
        }

        // Final batch
        if (! empty($batchDTOs)) {
            $this->processAndInsertBatch($batchDTOs, $estimate, $stats, $session);
        }
    }

    private function processAndInsertBatch(array $batch, Estimate $estimate, array &$stats, ImportSession $session): void
    {
        $aiBatch = [];

        // Step 1: Normative Matching
        foreach ($batch as $index => $item) {
            $dto = $item['dto'];
            $itemData = $this->prepareWorkData($dto, $estimate, $item['section_id']);

            $matched = false;
            // ... (keeping existing matcher logic) ...
            if ($dto->code) {
                $match = $this->matcher->findByCode($dto->code, ['fallback_to_name' => true, 'name' => $dto->itemName]);
                if ($match && isset($match['normative'])) {
                    $itemData = $this->matcher->fillFromNormative($match['normative'], $itemData);
                    $matched = true;
                }
            }

            if (! $matched && ! empty($dto->itemName)) {
                $semanticHit = $this->semanticMatcher->getBestNormativeMatch($dto->itemName, $dto->unit);
                if ($semanticHit && $semanticHit['similarity'] >= 0.5) {
                    $norm = \App\Models\NormativeRate::find($semanticHit['id']);
                    if ($norm) {
                        $itemData = $this->matcher->fillFromNormative($norm, $itemData);
                        $itemDataMetadata = is_string($itemData['metadata']) ? json_decode($itemData['metadata'], true) : $itemData['metadata'];
                        $itemDataMetadata['semantic_match'] = [
                            'similarity' => $semanticHit['similarity'],
                            'matched_name' => $semanticHit['name'],
                        ];
                        $itemData['metadata'] = json_encode($itemDataMetadata);
                        $matched = true;
                        Log::info("[ImportPipeline] SemanticMatch hit for '{$dto->itemName}' → '{$semanticHit['name']}' (sim={$semanticHit['similarity']})");
                    }
                }
            }

            if (! $matched) {
                $isGesnCode = ! empty($dto->code) && stripos($dto->code, 'ГЭСН') === 0;

                if ($isGesnCode) {
                    $itemData['item_type'] = 'work';
                } else {
                    $aiBatch[$index] = [
                        'code' => $dto->code ?? '',
                        'name' => $dto->itemName,
                        'unit' => $dto->unit,
                        'price' => (float) $dto->unitPrice,
                    ];
                }
            }

            $batch[$index]['prepared_data'] = $itemData;
        }

        // Step 2: AI Classification
        if (! empty($aiBatch)) {
            try {
                $aiResults = $this->classifier->classifyBatch(array_values($aiBatch));
                $aiKeys = array_keys($aiBatch);

                foreach ($aiResults as $subIndex => $result) {
                    $origIndex = $aiKeys[$subIndex];
                    if (isset($batch[$origIndex])) {
                        $batch[$origIndex]['prepared_data']['item_type'] = $result->type;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[ImportPipeline] AI Batch Classification failed: '.$e->getMessage());
            }
        }

        // Step 3: Sub-item Grouping (XML Parity)
        $preparedRows = array_column($batch, 'prepared_data');
        $groupedRows = $this->subItemGrouper->groupItems($preparedRows, $this->subItemState);

        // Step 5: Global Insert with Hierarchy Awareness
        $childrenBatch = [];
        $insertedParents = [];

        foreach ($groupedRows as $idx => $data) {
            if (isset($data['metadata']) && is_array($data['metadata'])) {
                $data['metadata'] = json_encode($data['metadata']);
            }

            $isSubItem = ! empty($data['is_sub_item']);
            $parentIndex = $data['_parent_index'] ?? null;

            // 🔧 ИСПРАВЛЕНИЕ: Удаляем технические поля, которых нет в БД
            unset(
                $data['is_sub_item'],
                $data['_parent_index'],
                $data['warnings'],
                $data['has_math_mismatch'],
                $data['anomaly'],
                $data['disable_sub_item_grouping']
            );

            if (! $isSubItem) {
                // Если накопились дочерние элементы, вставляем их перед родителем, чтобы сохранить относительный порядок БД
                if (! empty($childrenBatch)) {
                    EstimateItem::insert($childrenBatch);
                    $stats['items_created'] += count($childrenBatch);
                    $childrenBatch = [];
                }

                // Вставляем родителя отдельно, чтобы получить его ID для связей
                $id = DB::table('estimate_items')->insertGetId($data);
                $insertedParents[$idx] = $id;
                $this->subItemState['last_parent_id'] = $id; // для кросс-батч связей
                $stats['items_created']++;
            } else {
                // Это подпункт, привязываем его к родителю
                if ($parentIndex !== null && isset($insertedParents[$parentIndex])) {
                    $data['parent_work_id'] = $insertedParents[$parentIndex];
                } elseif ($parentIndex === 'prev' && isset($this->subItemState['last_parent_id'])) {
                    // Родитель был в предыдущем батче
                    $data['parent_work_id'] = $this->subItemState['last_parent_id'];
                }

                $childrenBatch[] = $data;
            }
        }

        if (! empty($childrenBatch)) {
            EstimateItem::insert($childrenBatch);
            $stats['items_created'] += count($childrenBatch);
        }
    }

    private function saveSection($dto, int $estimateId, array &$sectionMap): EstimateSection
    {
        // Resolve Parent
        $currentPath = $dto->sectionPath ?: $dto->sectionNumber;

        $parentId = $this->resolveParentSectionId((string) $currentPath, $sectionMap);

        $section = EstimateSection::create([
            'estimate_id' => $estimateId,
            'parent_section_id' => $parentId,
            'section_number' => (string) $dto->sectionNumber,
            'full_section_number' => (string) $currentPath,
            'name' => $dto->itemName,
            'sort_order' => $dto->rowNumber,
        ]);

        $sectionMap[$currentPath] = $section->id;

        return $section;
    }

    private function resolveSectionId($dto, array $sectionMap, ?int $lastSectionId): ?int
    {
        $path = $dto->sectionPath ?: $dto->sectionNumber;
        if ($path && isset($sectionMap[$path])) {
            return $sectionMap[$path];
        }

        return $lastSectionId;
    }

    private function pruneEmptyLeafSections(Estimate $estimate, string $handlerSlug): int
    {
        if ($handlerSlug === self::GRAND_SMETA_HANDLER_SLUG) {
            return 0;
        }

        $removed = 0;

        do {
            $sectionIds = EstimateSection::query()
                ->where('estimate_id', $estimate->id)
                ->whereNotExists(function ($query): void {
                    $query
                        ->selectRaw('1')
                        ->from('estimate_sections as child_sections')
                        ->whereColumn('child_sections.parent_section_id', 'estimate_sections.id');
                })
                ->whereNotExists(function ($query): void {
                    $query
                        ->selectRaw('1')
                        ->from('estimate_items as section_items')
                        ->whereColumn('section_items.estimate_section_id', 'estimate_sections.id')
                        ->whereNull('section_items.deleted_at');
                })
                ->pluck('id');

            if ($sectionIds->isEmpty()) {
                break;
            }

            $removed += EstimateSection::query()
                ->whereIn('id', $sectionIds)
                ->delete();
        } while (true);

        return $removed;
    }

    private function prepareWorkData($dto, Estimate $estimate, ?int $sectionId): array
    {
        $laborCost = $this->detectLaborCost($dto);
        $isInformative = $this->isInformativeGrandSmetaRow($dto);
        $isSubItem = $dto->isSubItem ?? false;
        $totalAmount = (float) ($dto->currentTotalAmount ?? ($dto->quantity * ($dto->unitPrice ?? 0)));

        // 1. Берем точные рублевые значения НР и СП напрямую от парсера ГрандСметы
        $overheadAmount = isset($dto->overheadAmount) ? (float) $dto->overheadAmount : 0;
        $profitAmount = isset($dto->profitAmount) ? (float) $dto->profitAmount : 0;

        // 2. Fallback: если парсер не нашел суммы, но есть ФОТ и настройки сметы
        if (! $isInformative && ! $isSubItem && $laborCost != 0) {
            if ($dto->overheadAmount === null) {
                $overheadAmount = round($laborCost * ($estimate->overhead_rate / 100), 2);
            }
            if ($dto->profitAmount === null) {
                $profitAmount = round($laborCost * ($estimate->profit_rate / 100), 2);
            }
        }

        // 3. В Гранд-Смете сумма позиции из колонки "Всего" - это Прямые Затраты!
        // Значит Итог с учетом налогов (полная стоимость) - это ПЗ + НР + СП
        // Для подпунктов математика налогов не применяется (их сумма заложена в ПЗ родителя)
        $actualTotalAmount = (new ImportRowPolicy($this->rowMapper))->totalAmount($dto, $overheadAmount, $profitAmount);
        $directCosts = ! $isInformative && ! $isSubItem
            ? $actualTotalAmount - $overheadAmount - $profitAmount
            : $totalAmount;

        // 4. Все подпункты делают задвоение, поэтому их исключаем из учета итоговых сумм
        $isNotAccounted = $isInformative || $isSubItem;

        $metadata = [
            'original_unit' => $dto->unit,
            'raw_data' => $dto->rawData,
            'source_row_number' => $dto->rowNumber,
            'source_worksheet_index' => $dto->worksheetIndex,
            'source_worksheet_name' => $dto->worksheetName,
            'overhead_rate' => $estimate->overhead_rate,
            'profit_rate' => $estimate->profit_rate,
            'is_informative_row' => $isInformative,
            'import_has_source_charges' => $dto->overheadAmount !== null || $dto->profitAmount !== null,
            'import_has_source_overhead' => $dto->overheadAmount !== null,
            'import_has_source_profit' => $dto->profitAmount !== null,
            'import_source_components_preserved' => $dto->overheadAmount !== null || $dto->profitAmount !== null || $dto->itemType === 'equipment',
        ];

        // ⭐ АВТОМАТИЧЕСКАЯ РЕГИСТРАЦИЯ АКТИВОВ:
        // Если это материал или оборудование, сохраняем в Каталог Активов (materials)
        $materialId = null;
        $itemType = $this->mapItemType($dto->itemType);

        // Не создаём активы для информационных строк
        if (! $isInformative && in_array($itemType, ['material', 'equipment', 'machinery']) && ! empty($dto->itemName)) {
            try {
                $material = $this->materialMatcher->findOrCreate(
                    $dto->code ?? ('MAT-'.uniqid()), // Fallback код, если пусто
                    $dto->itemName,
                    $dto->unit,
                    isset($dto->unitPrice) && $dto->unitPrice > 0 ? (float) $dto->unitPrice : null,
                    $estimate->organization_id,
                    $itemType === 'equipment' ? 'equipment' : 'material'
                );
                $materialId = $material->id;
            } catch (\Exception $e) {
                Log::warning("[ImportPipeline] Failed to auto-register material '{$dto->itemName}': ".$e->getMessage());
            }
        }

        return [
            'estimate_id' => $estimate->id,
            'estimate_section_id' => $sectionId,
            'name' => $dto->itemName,
            'description' => null,
            'measurement_unit_id' => $this->resolveUnitId($dto->unit, $estimate->organization_id),
            'material_id' => $materialId,
            'quantity' => $dto->quantity ?? 0,
            'unit_price' => $dto->unitPrice ?? 0,

            'base_unit_price' => $dto->baseUnitPrice ?? 0,
            'price_index' => $dto->priceIndex ?? 1,
            'current_unit_price' => $dto->currentUnitPrice ?? ($dto->unitPrice ?? 0),

            'labor_cost' => $laborCost,
            'overhead_amount' => $overheadAmount,
            'profit_amount' => $profitAmount,
            'direct_costs' => $directCosts,
            'total_amount' => $actualTotalAmount,
            'current_total_amount' => $actualTotalAmount,

            'materials_cost' => $dto->materialsCost ?? 0,
            'machinery_cost' => $dto->machineryCost ?? 0,
            'equipment_cost' => $dto->itemType === 'equipment' ? $actualTotalAmount - $overheadAmount - $profitAmount : 0,
            'labor_hours' => $this->detectLaborHours($dto),
            'machinery_hours' => $this->detectMachineryHours($dto),

            'normative_rate_code' => $dto->code,
            'position_number' => (string) ($dto->sectionNumber ?: ''),
            'item_type' => $itemType,
            'is_manual' => true,
            'is_sub_item' => $isSubItem,
            'created_at' => now(),
            'updated_at' => now(),
            'metadata' => json_encode($metadata),
            'is_not_accounted' => $isNotAccounted,
            'disable_sub_item_grouping' => is_array($dto->rawData)
                && (($dto->rawData['disable_sub_item_grouping'] ?? false) === true),
        ];
    }

    private function parentPathFromSectionPath(string $path): ?string
    {
        $slashPosition = strrpos($path, '/');
        $dotPosition = strrpos($path, '.');

        $positions = array_filter(
            [$slashPosition, $dotPosition],
            static fn ($position): bool => $position !== false && $position > 0
        );

        if ($positions === []) {
            return null;
        }

        $parentPath = substr($path, 0, max($positions));

        return $parentPath !== '' ? $parentPath : null;
    }

    private function resolveParentSectionId(string $path, array $sectionMap): ?int
    {
        $candidatePath = $path;

        while (($parentPath = $this->parentPathFromSectionPath($candidatePath)) !== null) {
            if (isset($sectionMap[$parentPath])) {
                return $sectionMap[$parentPath];
            }

            $candidatePath = $parentPath;
        }

        return null;
    }

    /**
     * Помощник для определения "информационных" строк GrandSmeta,
     * которые не должны участвовать в суммировании Прямых Затрат
     */
    private function isInformativeGrandSmetaRow($dto): bool
    {
        return (new ImportRowPolicy($this->rowMapper))->isInformative($dto);
    }

    /**
     * Помощник для детекции ФОТ из названия или обоснования GrandSmeta
     */
    private function detectLaborCost($dto): float
    {
        if (isset($dto->laborCost) && (float) $dto->laborCost != 0) {
            return (float) $dto->laborCost;
        }

        $name = mb_strtolower($dto->itemName ?? '');
        $code = mb_strtolower($dto->code ?? '');

        // ⭐ ИНКЛЮЗИВНЫЙ ПОИСК ФОТ В РЕСУРСАХ
        // Нам нужно ловить всё, что похоже на зарплату (ОТ, ЗТ, ОТм, ЗТм, ОТ(...)).
        $laborPrefixes = ['от(', 'зт(', 'отм(', 'зтм(', 'от ', 'отм ', 'зт ', 'зтм '];
        $isLaborName = false;

        if (in_array($name, ['от', 'отм', 'зт', 'зтм', 'от(зт)', 'отм(зтм)'])) {
            $isLaborName = true;
        } else {
            foreach ($laborPrefixes as $pref) {
                if (str_starts_with($name, $pref)) {
                    $isLaborName = true;
                    break;
                }
            }
        }

        if ($isLaborName) {
            return (float) ($dto->currentTotalAmount ?? 0);
        }

        return 0;
    }

    private function detectLaborHours($dto): float
    {
        if ($dto->itemType !== 'labor') {
            return 0;
        }

        $unit = mb_strtolower(trim((string) ($dto->unit ?? '')));
        $laborUnits = ['чел.-ч', 'чел-ч', 'чел.ч', 'чел/ч'];

        foreach ($laborUnits as $lu) {
            if (str_starts_with($unit, $lu)) {
                return (float) ($dto->quantity ?? 0);
            }
        }

        return (float) ($dto->quantity ?? 0);
    }

    private function detectMachineryHours($dto): float
    {
        if (! in_array($dto->itemType, ['machinery', 'equipment'], true)) {
            return 0;
        }

        $unit = mb_strtolower(trim((string) ($dto->unit ?? '')));
        $machineUnits = ['маш.-ч', 'маш-ч', 'маш.ч', 'маш/ч'];

        foreach ($machineUnits as $mu) {
            if (str_starts_with($unit, $mu)) {
                return (float) ($dto->quantity ?? 0);
            }
        }

        return 0;
    }

    private function mapItemType(?string $type): string
    {
        return match ($type) {
            'material' => 'material',
            'machinery' => 'machinery',
            'labor' => 'labor',
            'equipment' => 'equipment',
            'summary' => 'summary',
            default => 'work'
        };
    }

    private function normalizeUnitName(string $unitName): string
    {
        return mb_strtolower((new \App\BusinessModules\Features\BudgetEstimates\Services\Import\Validation\UnitNormalizationService)->normalize($unitName) ?? '');
    }

    private function resolveUnitId(?string $unitName, int $organizationId): ?int
    {
        if (empty($unitName)) {
            return null;
        }

        $normalized = $this->normalizeUnitName($unitName);
        $cacheKey = "{$organizationId}:{$normalized}";

        if (array_key_exists($cacheKey, $this->unitCache)) {
            return $this->unitCache[$cacheKey];
        }

        // Учитываем нормализованное имя при поиске
        $unit = MeasurementUnit::where('organization_id', $organizationId)
            ->whereRaw('LOWER(short_name) = ?', [$normalized])
            ->first();

        // Определение типа: material или work
        $unitType = 'material';
        foreach ($this->workUnitKeywords as $keyword) {
            if (mb_stripos($normalized, $keyword) !== false) {
                $unitType = 'work';
                break;
            }
        }

        if (! $unit) {
            $unit = MeasurementUnit::create([
                'organization_id' => $organizationId,
                'name' => mb_strlen($normalized) > 5 ? $unitName : $normalized, // Сохраняем оригинальное для длинных (если это не просто "м2")
                'short_name' => $normalized,
                'type' => $unitType,
                'is_system' => false,
            ]);
        }

        $this->unitCache[$cacheKey] = $unit->id;

        return $unit->id;
    }
}
