<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateTypeDetectionDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportFormatDetector;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportFormatRegistry;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportStructureResult;
use App\Models\ImportMemory;
use App\Models\ImportSession;
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

    public function uploadFile(UploadedFile $file, int $userId, int $organizationId): string
    {
        $storedFile = $this->fileStorage->store($file, $organizationId);

        $session = ImportSession::create([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'status' => 'uploading',
            'file_path' => $storedFile['path'],
            'file_name' => $storedFile['name'],
            'file_size' => $storedFile['size'],
            'file_format' => $storedFile['extension'],
            'options' => [],
            'stats' => ['progress' => 0],
        ]);

        Log::info('[EstimateImport] Import session created', [
            'session_id' => $session->id,
            'file_name' => $storedFile['name'],
            'file_size' => $storedFile['size'],
        ]);

        return $session->id;
    }

    public function detectEstimateType(string $sessionId): EstimateTypeDetectionDTO
    {
        $session = ImportSession::findOrFail($sessionId);
        $fullPath = $this->fileStorage->getAbsolutePath($session);

        try {
            $detection = $this->runtimeDetector->detect($session, $fullPath);
            if ($detection === null || $detection->confidence <= 0.0) {
                throw new RuntimeException(trans_message('estimate.import_unsupported_format'));
            }

            $options = $session->options ?? [];
            $options['format_handler'] = $detection->formatSlug;
            $options['runtime_detection'] = $detection->toArray();

            $session->update([
                'status' => 'detecting',
                'options' => $options,
            ]);

            return new EstimateTypeDetectionDTO(
                detectedType: $detection->detectedType,
                confidence: $detection->confidence,
                indicators: $detection->indicators,
                candidates: $detection->candidates,
                metadata: $detection->metadata + [
                    'format_slug' => $detection->formatSlug,
                    'label' => $detection->label,
                    'requires_confirmation' => $detection->requiresConfirmation,
                    'warnings' => $detection->warnings,
                ],
            );
        } catch (Throwable $e) {
            Log::error('[EstimateImport] Type detection failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function detectFormat(string $sessionId, ?int $suggestedHeaderRow = null): array
    {
        $session = $this->sessionWithDetectedHandler($sessionId);
        $fullPath = $this->fileStorage->getAbsolutePath($session);
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

        $session->update([
            'status' => 'detecting',
            'options' => $options,
        ]);

        return $structure->toArray();
    }

    public function preview(string $sessionId, ?array $columnMapping = null): EstimateImportDTO
    {
        $session = $this->sessionWithDetectedHandler($sessionId);
        $fullPath = $this->fileStorage->getAbsolutePath($session);
        $handler = $this->runtimeRegistry->bySlug((string) $session->options['format_handler']);
        $options = $session->options ?? [];

        if ($columnMapping !== null) {
            $columnMapping = $this->normalizeColumnMappingInput($columnMapping);
        }

        if ($columnMapping !== null && $columnMapping !== []) {
            $structure = $options['structure'] ?? [];
            $structure['column_mapping'] = $columnMapping;
            $structure['detected_columns'] = ImportStructureResult::detectedColumnsFromMapping($columnMapping);
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
    }

    public function learnFromSession(ImportSession $session): void
    {
        try {
            $fullPath = $this->fileStorage->getAbsolutePath($session);
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
        } catch (Throwable $e) {
            Log::warning('[EstimateImport] Structure learning failed', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function analyzeMatches(string $sessionId, int $organizationId): array
    {
        $preview = $this->preview($sessionId);
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

    public function execute(string $sessionId, array $matchingConfig, array $estimateSettings, bool $validateOnly = false): array
    {
        $session = $this->sessionWithDetectedHandler($sessionId);
        $options = $session->options ?? [];
        $options['matching_config'] = $matchingConfig;
        $options['estimate_settings'] = $estimateSettings;
        $options['validate_only'] = $validateOnly;

        $session->update([
            'options' => $options,
            'status' => 'queued',
        ]);

        \App\Jobs\ProcessEstimateImportJob::dispatch($sessionId);

        return [
            'status' => 'queued',
            'session_id' => $sessionId,
            'message' => trans_message('estimate.import_queued'),
        ];
    }

    public function getImportStatus(string $id): array
    {
        $session = ImportSession::find($id);

        if (!$session) {
            return [
                'status' => 'failed',
                'error' => trans_message('estimate.import_status_not_found'),
            ];
        }

        $progress = \Illuminate\Support\Facades\Cache::get("import_session_progress_{$id}");
        if ($progress === null) {
            $progress = $session->stats['progress'] ?? 0;
        }

        return [
            'status' => $this->mapSessionStatusToOldStatus($session->status),
            'progress' => (int) $progress,
            'message' => $this->translateStoredMessage($session->stats['message'] ?? null),
            'error' => $this->translateStoredMessage($session->error_message),
            'result' => $session->stats['result'] ?? null,
            'estimate_id' => $session->stats['estimate_id'] ?? null,
            'validation' => $session->stats['validation'] ?? ($session->options['validation'] ?? null),
        ];
    }

    public function getImportHistory(int $organizationId, int $limit = 50): Collection
    {
        return ImportSession::where('organization_id', $organizationId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function downloadTemplate(): StreamedResponse
    {
        return $this->templateService->generate();
    }

    private function sessionWithDetectedHandler(string $sessionId): ImportSession
    {
        $session = ImportSession::findOrFail($sessionId);
        if (!empty($session->options['format_handler'])) {
            return $session;
        }

        $this->detectEstimateType($sessionId);

        return ImportSession::findOrFail($sessionId);
    }

    private function structureFromSession(string $formatSlug, ImportSession $session): ?ImportStructureResult
    {
        $structure = $session->options['structure'] ?? null;
        if (!is_array($structure)) {
            return null;
        }

        $columnMapping = ImportStructureResult::columnMappingFromArray($structure);
        $detectedColumns = $structure['detected_columns'] ?? [];
        if ((!is_array($detectedColumns) || $detectedColumns === []) && $columnMapping !== []) {
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
            if (!is_string($field) || (!is_string($column) && !is_int($column))) {
                continue;
            }

            $column = strtoupper(trim((string) $column));
            if ($field === '' || $column === '') {
                continue;
            }

            $normalized[$field] = $column;
        }

        if (isset($normalized['current_total_amount']) && !isset($normalized['total_price'])) {
            $normalized['total_price'] = $normalized['current_total_amount'];
        }

        if (isset($normalized['section_number']) && !isset($normalized['position_number'])) {
            $normalized['position_number'] = $normalized['section_number'];
        }

        return $normalized;
    }

    private function mapSessionStatusToOldStatus(string $status): string
    {
        return match ($status) {
            'uploading', 'detecting', 'mapped', 'parsing', 'processing', 'enriching', 'validated' => 'processing',
            'completed' => 'completed',
            'failed' => 'failed',
            default => 'queued',
        };
    }

    private function translateStoredMessage(mixed $message): ?string
    {
        if (!is_string($message)) {
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
