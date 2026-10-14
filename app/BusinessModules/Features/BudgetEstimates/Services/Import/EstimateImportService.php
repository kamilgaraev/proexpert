<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateTypeDetectionDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Exceptions\UnsupportedEstimateImportFormatException;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportFormatDetector;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportFormatRegistry;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportStructureResult;
use App\Models\ImportMemory;
use App\Models\ImportSession;
use App\Models\Contract;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Exceptions\ImportSessionStateException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class EstimateImportService
{
    public function __construct(
        private FileStorageService $fileStorage,
        private TemplateService $templateService,
        private SignatureGenerator $signatureGenerator,
        private ImportFormatDetector $runtimeDetector,
        private ImportFormatRegistry $runtimeRegistry,
    ) {}

    public function uploadFile(UploadedFile $file, int $userId, int $organizationId, ?ImportAccessContext $context = null): string
    {
        $context = $this->accessContext($context);
        if ($userId !== (int) $context->actor->id || $organizationId !== $context->organizationId) {
            throw new AuthorizationException;
        }
        $storedFile = $this->fileStorage->store($file, $organizationId);

        try {
            $session = ImportSession::create([
                'user_id' => $userId,
                'organization_id' => $organizationId,
                'status' => 'uploading',
                'file_path' => $storedFile['path'],
                'file_name' => $storedFile['name'],
                'file_size' => $storedFile['size'],
                'file_format' => $storedFile['extension'],
                'options' => ['project_id' => $context->projectId],
                'stats' => ['progress' => 0],
            ]);
        } catch (Throwable $exception) {
            try {
                $this->fileStorage->delete(new ImportSession(['file_path' => $storedFile['path']]));
            } catch (Throwable $cleanupException) {
                Log::error('[EstimateImport] Upload compensation failed', ['organization_id' => $organizationId, 'exception_class' => $cleanupException::class]);
            }
            throw $exception;
        }

        Log::info('[EstimateImport] Import session created', [
            'session_id' => $session->id,
            'file_size' => $storedFile['size'],
        ]);

        return $session->id;
    }

    public function detectEstimateType(string $sessionId, ?ImportAccessContext $context = null): EstimateTypeDetectionDTO
    {
        $startedAt = microtime(true);
        $phase = 'storage';
        $token = (string) Str::uuid();
        $session = DB::transaction(function () use ($sessionId, $context, $startedAt, $token): ImportSession {
            $session = $this->getSession($sessionId, $context, true);
            $timeout = (int) config('octane.max_request_timeout', 30);
            $stats = $session->stats ?? [];
            unset($stats['reason_code'], $stats['correlation_id']);
            $session->update(['status' => 'detecting', 'error_message' => null, 'stats' => array_merge($stats, [
                'detection_started_at' => $startedAt, 'detection_phase' => 'storage',
                'detection_token' => $token, 'detection_deadline_at' => time() + ($timeout > 0 ? $timeout : 30) + 15,
            ])]);

            return $session;
        });
        Log::info('[EstimateImport] Type detection started', ['session_id' => $sessionId, 'phase' => $phase]);

        try {
            $detection = $this->fileStorage->withLocalCopy($session, function (string $fullPath) use ($session, $token, &$phase) {
                $phase = 'recognizing';
                $this->updateDetectionPhase($session, $token, $phase);
                $detection = $this->runtimeDetector->detect($session, $fullPath, function (string $handler) use ($session, $token, &$phase): void {
                    $phase = $handler;
                    $this->updateDetectionPhase($session, $token, $phase);
                    Log::info('[EstimateImport] Format detector started', ['session_id' => $session->id, 'handler' => $handler]);
                });
                if ($detection === null || $detection->confidence <= 0.0) {
                    throw UnsupportedEstimateImportFormatException::create();
                }

                return $detection;
            });
            DB::transaction(function () use ($session, $token, $detection): void {
                $current = $this->lockedDetectionSession($session);
                $this->assertCurrentDetection($current, $token);
                $current->update([
                    'options' => array_merge($current->options ?? [], [
                        'format_handler' => $detection->formatSlug, 'runtime_detection' => $detection->toArray(),
                    ]),
                    'stats' => array_merge($current->stats ?? [], ['detection_phase' => 'completed']),
                ]);
            });
            Log::info('[EstimateImport] Type detection completed', [
                'session_id' => $sessionId, 'format' => $detection->formatSlug,
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            return new EstimateTypeDetectionDTO(
                detectedType: $detection->detectedType,
                confidence: $detection->confidence,
                indicators: $detection->indicators,
                candidates: $detection->candidates,
                metadata: $detection->metadata + [
                    'format_slug' => $detection->formatSlug, 'label' => $detection->label,
                    'requires_confirmation' => $detection->requiresConfirmation, 'warnings' => $detection->warnings,
                ],
            );
        } catch (Throwable $exception) {
            DB::transaction(function () use ($session, $token, $phase, $exception): void {
                $current = $this->lockedDetectionSession($session);
                if ($current->status !== 'detecting' || ($current->stats['detection_token'] ?? null) !== $token) {
                    return;
                }
                $current->update([
                    'status' => 'failed',
                    'error_message' => $exception instanceof UnsupportedEstimateImportFormatException
                        ? 'estimate.import_unsupported_format'
                        : ($exception instanceof ImportSessionStateException ? $exception->translationKey : 'estimate.import_detect_type_error'),
                    'stats' => array_merge($current->stats ?? [], ['detection_phase' => $phase]),
                ]);
            });
            Log::error('[EstimateImport] Type detection failed', [
                'session_id' => $sessionId, 'phase' => $phase, 'exception_class' => $exception::class,
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            throw $exception;
        }
    }

    private function lockedDetectionSession(ImportSession $session): ImportSession
    {
        $projectId = (int) ($session->options['project_id'] ?? $session->options['estimate_settings']['project_id'] ?? 0);

        return ImportSession::query()->where('organization_id', $session->organization_id)
            ->where(function ($query) use ($projectId): void {
                $query->where('options->project_id', $projectId)->orWhere(function ($legacy) use ($projectId): void {
                    $legacy->whereNull('options->project_id')->where('options->estimate_settings->project_id', $projectId);
                });
            })->whereKey($session->id)->lockForUpdate()->firstOrFail();
    }

    private function assertCurrentDetection(ImportSession $session, string $token): void
    {
        if ($session->status !== 'detecting' || ($session->stats['detection_token'] ?? null) !== $token
            || now()->timestamp > (int) ($session->stats['detection_deadline_at'] ?? 0)) {
            throw new ImportSessionStateException('import_detection_interrupted', 'estimate.import_detection_interrupted');
        }
    }

    private function updateDetectionPhase(ImportSession $session, string $token, string $phase): void
    {
        DB::transaction(function () use ($session, $token, $phase): void {
            $current = $this->lockedDetectionSession($session);
            $this->assertCurrentDetection($current, $token);
            $current->update(['stats' => array_merge($current->stats ?? [], ['detection_phase' => $phase])]);
        });
    }

    public function detectFormat(string $sessionId, ?int $suggestedHeaderRow = null, ?ImportAccessContext $context = null, array $worksheetOptions = []): array
    {
        $this->sessionWithDetectedHandler($sessionId, $context);

        return DB::transaction(fn (): array => $this->detectFormatLocked($sessionId, $suggestedHeaderRow, $context, $worksheetOptions));
    }

    private function detectFormatLocked(string $sessionId, ?int $suggestedHeaderRow, ?ImportAccessContext $context, array $worksheetOptions): array
    {
        $session = $this->getSession($sessionId, $context, true);
        if (array_key_exists('worksheet_index', $worksheetOptions)) {
            $options = array_merge($session->options ?? [], ['worksheet_index' => $worksheetOptions['worksheet_index']]);
            unset($options['structure']);
            if (($session->options['worksheet_index'] ?? null) !== $worksheetOptions['worksheet_index']) {
                unset($options['manual_header_row'], $options['manual_structure_confirmed']);
            }
            $session->update(['options' => $options]);
        }

        return $this->fileStorage->withLocalCopy(
            $session,
            function (string $fullPath) use ($session, $suggestedHeaderRow): array {
                $handlerSlug = (string) ($session->options['format_handler'] ?? '');

                if ($handlerSlug === '') {
                    throw new RuntimeException(trans_message('estimate.import_format_not_detected'));
                }

                $handler = $this->runtimeRegistry->bySlug($handlerSlug);
                $structure = $suggestedHeaderRow !== null && method_exists($handler, 'detectStructureFromHeaderRow')
                    ? $handler->detectStructureFromHeaderRow($session, $fullPath, $suggestedHeaderRow)
                    : $handler->detectStructure($session, $fullPath);

                $options = $session->options ?? [];
                $options['format_handler'] = $handler->slug();
                $options['structure'] = $structure->toArray();
                if ($suggestedHeaderRow !== null) {
                    $options['manual_header_row'] = $suggestedHeaderRow;
                }

                $session->update([
                    'status' => 'detecting',
                    'options' => $options,
                ]);

                return $structure->toArray();
            },
        );
    }

    public function preview(string $sessionId, ?array $columnMapping = null, ?ImportAccessContext $context = null, array $worksheetOptions = []): EstimateImportDTO
    {
        $this->sessionWithDetectedHandler($sessionId, $context);

        return DB::transaction(fn (): EstimateImportDTO => $this->previewLocked($sessionId, $columnMapping, $context, $worksheetOptions));
    }

    private function previewLocked(string $sessionId, ?array $columnMapping, ?ImportAccessContext $context, array $worksheetOptions): EstimateImportDTO
    {
        $session = $this->getSession($sessionId, $context, true);
        if ($worksheetOptions !== []) {
            $options = array_merge($session->options ?? [], array_intersect_key($worksheetOptions, array_flip(['worksheet_index', 'worksheet_mappings'])));
            if (array_key_exists('worksheet_index', $worksheetOptions) && ($session->options['worksheet_index'] ?? null) !== $worksheetOptions['worksheet_index']) {
                unset($options['structure'], $options['manual_header_row'], $options['manual_structure_confirmed']);
            }
            if (isset($worksheetOptions['worksheet_mappings']) && ($session->options['worksheet_mappings'] ?? []) !== $worksheetOptions['worksheet_mappings']) {
                unset($options['structure']);
            }
            $session->update(['options' => $options]);
        }

        return $this->fileStorage->withLocalCopy(
            $session,
            function (string $fullPath) use ($session, $columnMapping, $worksheetOptions): EstimateImportDTO {
                $handler = $this->runtimeRegistry->bySlug((string) $session->options['format_handler']);
                $options = $session->options ?? [];
                if ($this->structureFromSession($handler->slug(), $session) === null) {
                    $options['structure'] = $handler->detectStructure($session, $fullPath)->toArray();
                    $session->update(['options' => $options]);
                }

                if ($columnMapping !== null) {
                    $columnMapping = $this->normalizeColumnMappingInput($columnMapping);
                }

                $manualMapping = (bool) ($worksheetOptions['manual_mapping'] ?? ($columnMapping !== null && $columnMapping !== []));
                if ($columnMapping !== null && ($columnMapping !== [] || $manualMapping)) {
                    $options['manual_structure_confirmed'] = $manualMapping && isset($options['manual_header_row']);
                    $structure = $options['structure'] ?? [];
                    $structure['column_mapping'] = $columnMapping;
                    $structure['detected_columns'] = ImportStructureResult::detectedColumnsFromMapping($columnMapping);
                    $structure['metadata']['manual_mapping'] = $manualMapping;
                    $selectedIndex = $options['worksheet_index'] ?? null;
                    if ($selectedIndex === null && $manualMapping) {
                        $selectedIndex = $structure['metadata']['worksheet_index'] ?? null;
                    }
                    if ($selectedIndex !== null) {
                        $overrides = array_values(array_filter($options['worksheet_mappings'] ?? [], static fn (array $override): bool => (int) ($override['worksheet_index'] ?? -1) !== (int) $selectedIndex));
                        $overrides[] = [
                            'worksheet_index' => (int) $selectedIndex, 'column_mapping' => $columnMapping,
                            'header_row' => $structure['header_row'], 'manual_mapping' => $manualMapping,
                        ];
                        $options['worksheet_mappings'] = $overrides;
                    }
                    $options['structure'] = $structure;
                    $session->update(['options' => $options]);
                    $session = $session->fresh();
                }

                $structure = $this->structureFromSession($handler->slug(), $session);
                if ($structure === null) {
                    $structure = $handler->detectStructure($session, $fullPath);
                    $options = $session->options ?? [];
                    $options['structure'] = $structure->toArray();
                    $session->update(['options' => $options]);
                    $session = $session->fresh();
                }

                $preview = $handler->preview($session, $fullPath, $structure);
                $options = $session->options ?? [];
                $options['preview_summary'] = $preview->summary;
                $options['validation'] = $preview->validation;
                $session->update([
                    'status' => 'mapped',
                    'options' => $options,
                ]);

                return new EstimateImportDTO(
                    fileName: $session->file_name,
                    fileSize: $session->file_size,
                    fileFormat: $session->file_format,
                    sections: $preview->sections,
                    items: $preview->items,
                    totals: $preview->totals,
                    metadata: $preview->metadata + [
                        'handler' => $handler->slug(),
                        'quality' => $preview->quality,
                        'summary' => $preview->summary,
                    ],
                    detectedColumns: $structure->detectedColumns,
                    rawHeaders: $structure->rawHeaders,
                    estimateType: $handler->slug(),
                    validationSummary: $preview->validation,
                );
            },
        );
    }

    public function learnFromSession(ImportSession $session): void
    {
        try {
            $this->fileStorage->withLocalCopy(
                $session,
                function (string $fullPath) use ($session): void {
                    $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

                    if (in_array($extension, ['xml', 'pdf'], true)) {
                        return;
                    }

                    $spreadsheet = IOFactory::load($fullPath);
                    $sheet = $spreadsheet->getActiveSheet();
                    $firstRow = [];

                    foreach ($sheet->getRowIterator(1, 1) as $row) {
                        foreach ($row->getCellIterator() as $cell) {
                            $firstRow[] = $cell->getValue();
                        }
                    }

                    $spreadsheet->disconnectWorksheets();

                    $signature = $this->signatureGenerator->generate($firstRow);
                    $mapping = $session->options['column_mapping'] ?? [];
                    if ($mapping === []) {
                        $structure = $session->options['structure'] ?? [];
                        $mapping = is_array($structure) ? ImportStructureResult::columnMappingFromArray($structure) : [];
                    }

                    if ($mapping === []) {
                        return;
                    }

                    ImportMemory::updateOrCreate(
                        [
                            'organization_id' => $session->organization_id,
                            'signature' => $signature,
                        ],
                        [
                            'user_id' => $session->user_id,
                            'file_format' => $extension,
                            'original_headers' => $firstRow,
                            'column_mapping' => $mapping,
                            'header_row' => $session->options['structure']['header_row'] ?? null,
                            'last_used_at' => now(),
                            'usage_count' => DB::raw('usage_count + 1'),
                        ],
                    );
                },
            );
        } catch (Throwable $e) {
            Log::warning('[EstimateImport] Structure learning failed', [
                'session_id' => $session->id,
                'exception_class' => $e::class,
            ]);
        }
    }

    public function analyzeMatches(string $sessionId, int $organizationId, ?ImportAccessContext $context = null): array
    {
        $context = $this->accessContext($context);
        if ($organizationId !== $context->organizationId) {
            throw new AuthorizationException;
        }
        $preview = $this->preview($sessionId, null, $context);
        $items = array_map(static function (array $item): array {
            return [
                'row_number' => $item['row_number'] ?? null,
                'item_name' => $item['item_name'] ?? '',
                'code' => $item['code'] ?? null,
                'unit' => $item['unit'] ?? null,
                'quantity' => $item['quantity'] ?? null,
                'unit_price' => $item['unit_price'] ?? null,
                'total_amount' => $item['current_total_amount'] ?? $item['total_amount'] ?? null,
                'status' => 'pending',
            ];
        }, $preview->items);

        return [
            'items' => $items,
            'summary' => [
                'organization_id' => $organizationId,
                'items_count' => count($items),
                'sections_count' => count($preview->sections),
                'total_amount' => $preview->getTotalAmount(),
            ],
        ];
    }

    public function execute(string $sessionId, array $matchingConfig, array $estimateSettings, bool $validateOnly = false, ?ImportAccessContext $context = null, bool $retry = false): array
    {
        $context = $this->accessContext($context);
        if (isset($estimateSettings['project_id']) && (int) $estimateSettings['project_id'] !== $context->projectId) {
            throw new ImportSessionStateException('import_project_mismatch', 'estimate.import_project_mismatch', 422);
        }
        if (! empty($estimateSettings['contract_id'])) {
            Contract::query()->where('organization_id', $context->organizationId)->where('project_id', $context->projectId)
                ->findOrFail($estimateSettings['contract_id']);
        }
        $estimateSettings['organization_id'] = $context->organizationId;
        $estimateSettings['project_id'] = $context->projectId;
        $this->getSession($sessionId, $context);

        return DB::transaction(function () use ($sessionId, $matchingConfig, $estimateSettings, $validateOnly, $context, $retry): array {
            $session = ImportSession::query()->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            if ($session->status === 'detecting' && ($session->stats['detection_phase'] ?? null) !== 'completed') {
                $this->recoverInterruptedSession($session);
                if ($session->status === 'detecting') {
                    throw new ImportSessionStateException('import_session_locked', 'estimate.import_session_locked');
                }
            }
            if ($session->isActive() || $session->isCompleted() || ($session->status === 'validated' && $validateOnly)) {
                return $this->getImportStatus($sessionId, $context) + ['session_id' => $sessionId];
            }
            $this->ensureUnexpired($session);
            if ($session->isFailed() && (! $retry || ! empty($session->stats['estimate_id']))) {
                throw new ImportSessionStateException('import_retry_required', 'estimate.import_retry_required');
            }
            if (empty($session->options['format_handler'])) {
                throw new ImportSessionStateException('import_format_not_detected', 'estimate.import_format_not_detected', 422);
            }
            $options = array_merge($session->options ?? [], [
                'matching_config' => $matchingConfig, 'estimate_settings' => $estimateSettings,
                'validate_only' => $validateOnly, 'run_token' => (string) Str::uuid(), 'execution_user_id' => (int) $context->actor->id,
            ]);
            $session->update(['options' => $options, 'status' => 'queued', 'error_message' => null, 'stats' => [
                'progress' => 0, 'queued_deadline_at' => now()->addHours(24)->timestamp,
            ]]);
            Cache::forget("import_session_progress_{$sessionId}");
            \App\Jobs\ProcessEstimateImportJob::dispatch($sessionId, $options['run_token'])->afterCommit();

            return ['status' => 'queued', 'session_id' => $sessionId, 'message' => trans_message('estimate.import_queued')];
        });
    }

    public function getImportStatus(string $id, ?ImportAccessContext $context = null): array
    {
        $session = $this->getSession($id, $context);
        $this->recoverInterruptedSession($session);
        if (! $session->isActive() && ! $session->isTerminal()
            && $session->created_at->copy()->addHours(24)->isPast()
            && ($session->status !== 'detecting' || ($session->stats['detection_phase'] ?? null) === 'completed')) {
            ImportSession::query()->whereKey($session->id)->where('status', $session->status)->update(['status' => 'expired']);
            $session->refresh();
        }

        $progress = $session->isTerminal() ? ($session->stats['progress'] ?? 0) : Cache::get("import_session_progress_{$id}");
        if ($progress === null) {
            $progress = $session->stats['progress'] ?? 0;
        }

        return [
            'status' => $this->mapSessionStatusToOldStatus($session->status),
            'progress' => (int) $progress,
            'message' => $session->isFailed()
                ? ImportErrorMessage::fromStored($session->stats['message'] ?? null)
                : $this->translateStoredMessage($session->stats['message'] ?? null),
            'error' => ImportErrorMessage::fromStored($session->error_message),
            'result' => $session->stats['result'] ?? null,
            'estimate_id' => $session->stats['estimate_id'] ?? null,
            'validation' => $session->stats['validation'] ?? ($session->options['validation'] ?? null),
            'reason_code' => $session->stats['reason_code'] ?? null,
            'correlation_id' => $session->stats['correlation_id'] ?? null,
            'expires_at' => $session->created_at->copy()->addHours(24)->toIso8601String(),
        ];
    }

    public function getImportHistory(int $organizationId, int $limit = 50, ?ImportAccessContext $context = null): Collection
    {
        $context = $this->accessContext($context);
        if ($organizationId !== $context->organizationId) {
            throw new AuthorizationException;
        }
        return ImportSession::where('organization_id', $organizationId)
            ->where(function ($query) use ($context): void {
                $query->where('options->project_id', $context->projectId)->orWhere(function ($legacy) use ($context): void {
                    $legacy->whereNull('options->project_id')->where('options->estimate_settings->project_id', $context->projectId);
                });
            })
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->each(function (ImportSession $session): void {
                $session->error_message = ImportErrorMessage::fromStored($session->error_message);
                $stats = $session->stats ?? [];
                unset($stats['technical_error_message'], $stats['error_trace']);
                if ($session->isFailed()) {
                    $stats['message'] = ImportErrorMessage::fromStored($stats['message'] ?? null);
                }
                $session->stats = $stats;
            });
    }

    public function downloadTemplate(?ImportAccessContext $context = null): StreamedResponse
    {
        $this->accessContext($context);
        return $this->templateService->generate();
    }

    private function accessContext(?ImportAccessContext $context): ImportAccessContext
    {
        if ($context === null) {
            $actor = auth()->user();
            $project = request()->route('project');
            $projectId = is_object($project) ? $project->id : $project;
            if (! $actor instanceof \App\Models\User || ! is_numeric($projectId)) {
                throw new AuthorizationException;
            }
            $context = new ImportAccessContext($actor, (int) $actor->current_organization_id, (int) $projectId);
        }
        $context->authorize();

        return $context;
    }

    public function getSession(string $id, ?ImportAccessContext $context = null, bool $mutable = false): ImportSession
    {
        $context = $this->accessContext($context);
        $session = ImportSession::query()->where('organization_id', $context->organizationId)
            ->where(function ($query) use ($context): void {
                $query->where('options->project_id', $context->projectId)->orWhere(function ($legacy) use ($context): void {
                    $legacy->whereNull('options->project_id')->where('options->estimate_settings->project_id', $context->projectId);
                });
            })->when($mutable, static fn ($query) => $query->lockForUpdate())->findOrFail($id);
        if ($mutable) {
            $this->recoverInterruptedSession($session);
            $this->ensureUnexpired($session);
            if ($session->isActive() || $session->isCompleted()
                || ($session->status === 'detecting' && ($session->stats['detection_phase'] ?? null) !== 'completed')) {
                throw new ImportSessionStateException('import_session_locked', 'estimate.import_session_locked');
            }
        }

        return $session;
    }

    private function ensureUnexpired(ImportSession $session): void
    {
        if ($session->created_at->copy()->addHours(24)->isPast() || $session->status === 'expired') {
            throw new ImportSessionStateException('import_session_expired', 'estimate.import_session_expired', 410);
        }
    }

    public function recoverInterruptedSession(ImportSession $session): void
    {
        $detectionDeadline = $session->stats['detection_deadline_at'] ?? null;
        if ($session->status === 'detecting' && ($session->stats['detection_phase'] ?? null) !== 'completed'
            && is_numeric($detectionDeadline) && now()->timestamp > (int) $detectionDeadline) {
            $correlationId = (string) Str::uuid();
            $updated = ImportSession::query()->whereKey($session->id)->where('status', 'detecting')
                ->where('stats->detection_deadline_at', $detectionDeadline)->where(function ($phase): void {
                    $phase->where('stats->detection_phase', '!=', 'completed')->orWhereNull('stats->detection_phase');
                })
                ->where('stats->detection_token', $session->stats['detection_token'] ?? null)
                ->update(['status' => 'failed', 'error_message' => 'estimate.import_detection_interrupted', 'stats' => json_encode(array_merge($session->stats ?? [], [
                    'reason_code' => 'import_detection_interrupted', 'correlation_id' => $correlationId,
                ]), JSON_THROW_ON_ERROR)]);
            if ($updated > 0) {
                Log::error('[EstimateImport] Type detection interrupted', ['session_id' => $session->id, 'correlation_id' => $correlationId, 'reason_code' => 'import_detection_interrupted']);
            }
            $session->refresh();
            return;
        }
        $deadline = $session->status === 'queued'
            ? ($session->stats['queued_deadline_at'] ?? null)
            : ($session->stats['worker_deadline_at'] ?? null);
        if (! $session->isActive() || ! is_numeric($deadline) || now()->timestamp <= (int) $deadline) {
            return;
        }
        $correlationId = (string) Str::uuid();
        $updated = ImportSession::query()->whereKey($session->id)->where('status', $session->status)
            ->where('options->run_token', $session->options['run_token'] ?? null)
            ->update(['status' => 'failed', 'error_message' => 'estimate.import_worker_interrupted', 'stats' => json_encode(array_merge($session->stats ?? [], [
                'message' => 'estimate.import_worker_interrupted', 'reason_code' => 'import_worker_interrupted', 'correlation_id' => $correlationId,
            ]), JSON_THROW_ON_ERROR)]);
        if ($updated > 0) {
            Cache::forget("import_session_progress_{$session->id}");
            Log::error('[EstimateImport] Worker deadline exceeded', ['session_id' => $session->id, 'correlation_id' => $correlationId, 'reason_code' => 'import_worker_interrupted']);
        }
        $session->refresh();
    }

    public function cleanupInactiveSources(int $limit = 100): int
    {
        $count = 0;
        $sessions = ImportSession::query()->where('created_at', '<=', now()->subHours(24))
            ->whereNotNull('file_path')->where(function ($query): void {
                $query->whereNotIn('status', ['queued', 'parsing', 'processing', 'enriching', 'detecting'])
                    ->orWhere(function ($detecting): void {
                        $detecting->where('status', 'detecting')->where('stats->detection_phase', 'completed');
                    });
            })
            ->orderBy('created_at')->limit($limit)->get();
        foreach ($sessions as $candidate) {
            DB::transaction(function () use ($candidate, &$count): void {
                $session = ImportSession::query()->whereKey($candidate->id)->lockForUpdate()->first();
                if (! $session || $session->isActive()
                    || ($session->status === 'detecting' && ($session->stats['detection_phase'] ?? null) !== 'completed') || ! $session->file_path) {
                    return;
                }
                $this->fileStorage->delete($session);
                $stats = array_merge($session->stats ?? [], ['source_deleted_at' => now()->toIso8601String()]);
                $session->update(['file_path' => null, 'status' => $session->isTerminal() ? $session->status : 'expired', 'stats' => $stats]);
                $count++;
            });
        }

        return $count;
    }

    private function sessionWithDetectedHandler(string $sessionId, ?ImportAccessContext $context = null): ImportSession
    {
        $session = $this->getSession($sessionId, $context, true);
        if (! empty($session->options['format_handler'])) {
            return $session;
        }

        $this->detectEstimateType($sessionId, $context);

        return $this->getSession($sessionId, $context, true);
    }

    private function structureFromSession(string $formatSlug, ImportSession $session): ?ImportStructureResult
    {
        $structure = $session->options['structure'] ?? null;
        if (! is_array($structure)) {
            return null;
        }

        $columnMapping = ($structure['metadata']['manual_mapping'] ?? false)
            ? $this->normalizeColumnMappingInput($structure['column_mapping'] ?? [])
            : ImportStructureResult::columnMappingFromArray($structure);
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

    private function normalizeColumnMappingInput(array $mapping): array
    {
        $normalized = [];
        foreach ($mapping as $field => $column) {
            if (! is_string($field) || (! is_string($column) && ! is_int($column))) {
                continue;
            }

            $column = strtoupper(trim((string) $column));
            if ($field === '' || $column === '') {
                continue;
            }

            $normalized[$field] = $column;
        }

        if (isset($normalized['current_total_amount']) && ! isset($normalized['total_price'])) {
            $normalized['total_price'] = $normalized['current_total_amount'];
        }

        if (isset($normalized['section_number']) && ! isset($normalized['position_number'])) {
            $normalized['position_number'] = $normalized['section_number'];
        }

        return $normalized;
    }

    private function mapSessionStatusToOldStatus(string $status): string
    {
        return match ($status) {
            'uploading', 'detecting', 'mapped', 'parsing', 'processing', 'enriching' => 'processing',
            'validated' => 'validated',
            'expired' => 'expired',
            'completed' => 'completed',
            'failed' => 'failed',
            default => 'queued',
        };
    }

    private function translateStoredMessage(mixed $message): ?string
    {
        if (! is_string($message)) {
            return null;
        }

        $message = trim($message);
        if ($message === '') {
            return null;
        }

        return $this->looksLikeTranslationKey($message) ? trans_message($message) : $message;
    }

    private function looksLikeTranslationKey(string $message): bool
    {
        return preg_match('/^[a-z0-9_]+\.[a-z0-9_.-]+$/i', $message) === 1;
    }
}
