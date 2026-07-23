<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationMutationPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationUnitJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use function trans_message;

final class ManageEstimateGenerationDocumentPages
{
    public const STATUS_READY = 'ready';
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXCLUDED = 'excluded';

    public function __construct(
        private EstimateGenerationMutationPolicy $policy,
        private ReconcileEstimateGenerationDocuments $reconciler,
        private DocumentGenerationReadinessService $readiness,
    ) {}

    /**
     * @param list<int> $pageNumbers
     */
    public function retry(
        EstimateGenerationSession $session,
        EstimateGenerationDocument $document,
        int $expectedVersion,
        array $pageNumbers,
        ?string $reason,
    ): DocumentPageActionResult {
        $dispatch = [];
        [$session, $document, $pages, $pageSummary] = DB::transaction(function () use ($session, $document, $expectedVersion, $pageNumbers, $reason, &$dispatch): array {
            [$lockedSession, $lockedDocument, $pages] = $this->lockScope($session, $document, $expectedVersion, $pageNumbers);
            $attemptId = (string) Str::uuid();
            $sourceVersion = (string) $lockedDocument->source_version;

            if ($sourceVersion === '') {
                throw ValidationException::withMessages(['document' => [trans_message('estimate_generation.document_pages_retry_not_allowed')]]);
            }

            foreach ($pages as $page) {
                $unit = $this->unitForPage($lockedDocument, $page, $sourceVersion);
                $this->deletePageLineage($page);
                $page->forceFill([
                    'processing_unit_id' => $unit->id,
                    'source_version' => $sourceVersion,
                    'output_version' => null,
                    'width' => null,
                    'height' => null,
                    'rotation' => null,
                    'language_codes' => [],
                    'text' => null,
                    'text_hash' => null,
                    'confidence' => null,
                    'raw_payload_path' => null,
                    'normalized_payload' => [],
                    'quality_flags' => [],
                    'status' => self::STATUS_QUEUED,
                    'excluded_at' => null,
                    'excluded_reason' => null,
                    'retry_attempt_id' => $attemptId,
                    'last_retry_requested_at' => now(),
                ])->save();
                $unit->forceFill([
                    'status' => DocumentProcessingUnitStatus::Pending,
                    'attempt_count' => 0,
                    'claim_token' => null,
                    'lease_expires_at' => null,
                    'output_version' => null,
                    'output_count' => 0,
                    'failure_code' => null,
                    'failure_fingerprint' => null,
                    'started_at' => null,
                    'completed_at' => null,
                    'failed_at' => null,
                    'last_dispatched_at' => null,
                    'next_dispatch_at' => null,
                    'metadata' => [
                        ...(is_array($unit->metadata) ? $unit->metadata : []),
                        'page_retry_requested_at' => now()->toISOString(),
                        'page_retry_reason' => $this->reason($reason),
                        'page_retry_attempt_id' => $attemptId,
                    ],
                ])->save();
                $dispatch[] = [(int) $unit->id, $sourceVersion];
            }

            $lockedDocument->forceFill([
                'status' => self::STATUS_PROCESSING,
                'processing_stage' => 'preflight',
                'progress_percent' => max(10, (int) $lockedDocument->progress_percent),
                'units_reconciled_source_version' => null,
                'units_reconcile_claim_token' => null,
                'units_reconcile_lease_expires_at' => null,
                'meta' => [
                    ...(is_array($lockedDocument->meta) ? $lockedDocument->meta : []),
                    'page_retry_requested_at' => now()->toISOString(),
                    'page_retry_reason' => $this->reason($reason),
                    'page_retry_attempt_id' => $attemptId,
                    'page_retry_numbers' => $pageNumbers,
                ],
            ])->save();
            $this->refreshDocumentAggregate($lockedDocument);

            return [
                $this->reconciler->changed($lockedSession),
                $lockedDocument->fresh(['session']) ?? $lockedDocument,
                $this->documentPages($lockedDocument),
                $this->pageSummary($lockedDocument),
            ];
        }, 3);

        foreach ($dispatch as [$unitId, $sourceVersion]) {
            ProcessEstimateGenerationUnitJob::dispatch($unitId, $sourceVersion)
                ->onConnection(ProcessEstimateGenerationUnitJob::CONNECTION)
                ->onQueue(ProcessEstimateGenerationUnitJob::RECOVERY_QUEUE)
                ->afterCommit();
        }

        $session = $session->fresh(['documents']) ?? $session;

        return new DocumentPageActionResult(
            $document,
            $pages,
            $this->readiness->evaluate($session)['summary'],
            'estimate_generation.document_pages_retry_queued',
            $pageSummary,
        );
    }

    /**
     * @param list<int> $pageNumbers
     */
    public function exclude(
        EstimateGenerationSession $session,
        EstimateGenerationDocument $document,
        int $expectedVersion,
        array $pageNumbers,
        ?string $reason,
    ): DocumentPageActionResult {
        [$session, $document, $pages, $pageSummary] = DB::transaction(function () use ($session, $document, $expectedVersion, $pageNumbers, $reason): array {
            [$lockedSession, $lockedDocument, $pages] = $this->lockScope($session, $document, $expectedVersion, $pageNumbers);

            foreach ($pages as $page) {
                $this->deletePageLineage($page);
                $page->forceFill([
                    'status' => self::STATUS_EXCLUDED,
                    'excluded_at' => now(),
                    'excluded_reason' => $this->reason($reason),
                ])->save();
            }

            $this->refreshDocumentAggregate($lockedDocument);

            return [
                $this->reconciler->changed($lockedSession),
                $lockedDocument->fresh(['session']) ?? $lockedDocument,
                $this->documentPages($lockedDocument),
                $this->pageSummary($lockedDocument),
            ];
        }, 3);
        $session = $session->fresh(['documents']) ?? $session;

        return new DocumentPageActionResult(
            $document,
            $pages,
            $this->readiness->evaluate($session)['summary'],
            'estimate_generation.document_pages_excluded',
            $pageSummary,
        );
    }

    /**
     * @param list<int> $pageNumbers
     */
    public function restore(
        EstimateGenerationSession $session,
        EstimateGenerationDocument $document,
        int $expectedVersion,
        array $pageNumbers,
    ): DocumentPageActionResult {
        [$session, $document, $pages, $pageSummary] = DB::transaction(function () use ($session, $document, $expectedVersion, $pageNumbers): array {
            [$lockedSession, $lockedDocument, $pages] = $this->lockScope($session, $document, $expectedVersion, $pageNumbers);

            foreach ($pages as $page) {
                $status = (string) $page->text !== '' ? self::STATUS_READY : self::STATUS_NEEDS_REVIEW;
                $page->forceFill([
                    'status' => $status,
                    'excluded_at' => null,
                    'excluded_reason' => null,
                ])->save();
            }

            $this->refreshDocumentAggregate($lockedDocument);

            return [
                $this->reconciler->changed($lockedSession),
                $lockedDocument->fresh(['session']) ?? $lockedDocument,
                $this->documentPages($lockedDocument),
                $this->pageSummary($lockedDocument),
            ];
        }, 3);
        $session = $session->fresh(['documents']) ?? $session;

        return new DocumentPageActionResult(
            $document,
            $pages,
            $this->readiness->evaluate($session)['summary'],
            'estimate_generation.document_pages_restored',
            $pageSummary,
        );
    }

    /**
     * @param list<int> $pageNumbers
     * @return array{0: EstimateGenerationSession, 1: EstimateGenerationDocument, 2: Collection<int, EstimateGenerationDocumentPage>}
     */
    private function lockScope(EstimateGenerationSession $session, EstimateGenerationDocument $document, int $expectedVersion, array $pageNumbers): array
    {
        $lockedSession = EstimateGenerationSession::query()->lockForUpdate()->findOrFail($session->getKey());
        $lockedDocument = EstimateGenerationDocument::query()
            ->where('organization_id', $lockedSession->organization_id)
            ->where('project_id', $lockedSession->project_id)
            ->where('session_id', $lockedSession->id)
            ->lockForUpdate()
            ->findOrFail($document->getKey());

        $this->policy->documents($lockedSession, $expectedVersion);
        if ($lockedDocument->status === 'ignored') {
            throw ValidationException::withMessages(['document' => [trans_message('estimate_generation.document_pages_action_not_allowed')]]);
        }

        $pages = EstimateGenerationDocumentPage::query()
            ->where('organization_id', $lockedSession->organization_id)
            ->where('project_id', $lockedSession->project_id)
            ->where('session_id', $lockedSession->id)
            ->where('document_id', $lockedDocument->id)
            ->whereIn('page_number', $pageNumbers)
            ->lockForUpdate()
            ->orderBy('page_number')
            ->get();

        if ($pages->count() !== count($pageNumbers)) {
            throw ValidationException::withMessages(['page_numbers' => [trans_message('estimate_generation.document_pages_not_found')]]);
        }

        return [$lockedSession, $lockedDocument, $pages];
    }

    private function unitForPage(EstimateGenerationDocument $document, EstimateGenerationDocumentPage $page, string $sourceVersion): EstimateGenerationProcessingUnit
    {
        $unit = EstimateGenerationProcessingUnit::query()
            ->where('organization_id', $document->organization_id)
            ->where('project_id', $document->project_id)
            ->where('session_id', $document->session_id)
            ->where('document_id', $document->id)
            ->where('unit_index', $page->page_number)
            ->where('source_version', $sourceVersion)
            ->first();

        if ($unit instanceof EstimateGenerationProcessingUnit) {
            return $unit;
        }

        return EstimateGenerationProcessingUnit::query()->create([
            'organization_id' => $document->organization_id,
            'project_id' => $document->project_id,
            'session_id' => $document->session_id,
            'document_id' => $document->id,
            'unit_type' => $this->unitType($document),
            'unit_index' => $page->page_number,
            'source_version' => $sourceVersion,
            'status' => DocumentProcessingUnitStatus::Pending,
            'attempt_count' => 0,
            'output_count' => 0,
            'locator' => ['page' => $page->page_number],
            'metadata' => [],
        ]);
    }

    private function deletePageLineage(EstimateGenerationDocumentPage $page): void
    {
        $page->facts()->delete();
        $page->drawingElements()->delete();
        $page->quantityTakeoffs()->delete();
        $page->scopeInferences()->delete();
    }

    private function refreshDocumentAggregate(EstimateGenerationDocument $document): void
    {
        $pages = $this->documentPages($document)->reject(
            static fn (EstimateGenerationDocumentPage $page): bool => (string) $page->status === self::STATUS_EXCLUDED,
        );
        $pageSummary = $this->pageSummary($document);
        $qualityFlags = array_values(array_filter(
            array_map('strval', is_array($document->quality_flags) ? $document->quality_flags : []),
            static fn (string $flag): bool => $flag !== 'pages_excluded_from_estimation',
        ));
        if ($pageSummary['excluded'] > 0) {
            $qualityFlags[] = 'pages_excluded_from_estimation';
        }
        $status = $this->documentStatus($document);
        $document->forceFill([
            'status' => $status,
            'processing_stage' => $status === self::STATUS_PROCESSING ? 'preflight' : 'completed',
            'progress_percent' => $status === self::STATUS_PROCESSING ? max(10, (int) $document->progress_percent) : 100,
            'extracted_text' => $pages->pluck('text')->filter()->implode("\n\n"),
            'structured_payload' => [
                'source_version' => (string) $document->source_version,
                'pages' => $pages->map(static fn (EstimateGenerationDocumentPage $page): array => [
                    'page_number' => (int) $page->page_number,
                    'text' => $page->text,
                    'confidence' => $page->confidence,
                    'normalized_payload' => $page->normalized_payload ?? [],
                    'status' => $page->status ?? self::STATUS_READY,
                ])->values()->all(),
            ],
            'processed_page_count' => $pages->whereNotNull('text')->count(),
            'quality_flags' => array_values(array_unique($qualityFlags)),
        ])->save();
    }

    private function documentStatus(EstimateGenerationDocument $document): string
    {
        $pages = $this->documentPages($document);
        $included = $pages->reject(static fn (EstimateGenerationDocumentPage $page): bool => (string) $page->status === self::STATUS_EXCLUDED);

        if ($included->isEmpty()) {
            return self::STATUS_NEEDS_REVIEW;
        }
        if ($included->contains(static fn (EstimateGenerationDocumentPage $page): bool => in_array((string) $page->status, [self::STATUS_QUEUED, self::STATUS_PROCESSING], true))) {
            return self::STATUS_PROCESSING;
        }
        if ($included->contains(static fn (EstimateGenerationDocumentPage $page): bool => in_array((string) $page->status, [self::STATUS_FAILED, self::STATUS_NEEDS_REVIEW], true))) {
            return self::STATUS_NEEDS_REVIEW;
        }

        return self::STATUS_READY;
    }

    /**
     * @return Collection<int, EstimateGenerationDocumentPage>
     */
    private function documentPages(EstimateGenerationDocument $document): Collection
    {
        return EstimateGenerationDocumentPage::query()
            ->where('organization_id', $document->organization_id)
            ->where('project_id', $document->project_id)
            ->where('session_id', $document->session_id)
            ->where('document_id', $document->id)
            ->orderBy('page_number')
            ->get();
    }

    /**
     * @return array{total: int, included: int, excluded: int, action_required: int, processing: int}
     */
    private function pageSummary(EstimateGenerationDocument $document): array
    {
        $pages = $this->documentPages($document);

        return [
            'total' => $pages->count(),
            'included' => $pages->filter(static fn (EstimateGenerationDocumentPage $page): bool => (string) $page->status !== self::STATUS_EXCLUDED)->count(),
            'excluded' => $pages->filter(static fn (EstimateGenerationDocumentPage $page): bool => (string) $page->status === self::STATUS_EXCLUDED)->count(),
            'action_required' => $pages->filter(static fn (EstimateGenerationDocumentPage $page): bool => in_array((string) $page->status, [self::STATUS_NEEDS_REVIEW, self::STATUS_FAILED], true))->count(),
            'processing' => $pages->filter(static fn (EstimateGenerationDocumentPage $page): bool => in_array((string) $page->status, [self::STATUS_QUEUED, self::STATUS_PROCESSING], true))->count(),
        ];
    }

    private function reason(?string $reason): ?string
    {
        return is_string($reason) && trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : null;
    }

    private function unitType(EstimateGenerationDocument $document): string
    {
        return match ((string) $document->mime_type) {
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'text/csv' => DocumentUnitType::SpreadsheetSheet->value,
            default => DocumentUnitType::PdfPage->value,
        };
    }
}
