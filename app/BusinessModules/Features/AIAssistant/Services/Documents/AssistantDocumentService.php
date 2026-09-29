<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Models\Credits\AICreditReservation;
use App\Models\File;
use App\Models\Organization;
use App\Models\User;
use App\Services\Credits\AICreditService;
use App\Services\Storage\FileService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AssistantDocumentService
{
    private const MAX_BYTES = 25_000_000;

    public function __construct(
        private readonly FileService $files,
        private readonly DocumentTextExtractor $extractor,
        private readonly AssistantDataAccessPolicy $policy,
        private readonly AssistantDocumentOcrClient $ocr,
        private readonly AICreditService $credits,
        private readonly AssistantDocumentFileResolver $resolver,
    ) {}

    public function register(User $actor, int $organizationId, string $parentType, string|int $parentId, string $path, string $filename, string $mimeType, ?int $projectId = null): AIAssistantDocument
    {
        $native = AssistantNativeDocumentRegistry::adapter($parentType);
        if ($native !== null) {
            $document = $native->map($actor, $organizationId, $parentType, $parentId, $path);
            if ($projectId !== null && (int) $document->project_id !== $projectId) {
                throw new RuntimeException('ai_assistant_document_project_invalid');
            }
            return $document;
        }
        $this->assertContentAccess($actor, $organizationId, $parentType, $parentId);
        if ($parentType === 'design_artifact_version') {
            app(AssistantDesignFileAdapter::class)->map($actor, $organizationId, $parentId, $path);
        }
        if (AssistantNativeFileRegistry::supports($parentType)) {
            AssistantNativeFileRegistry::adapter($parentType)?->map($actor, $organizationId, $parentType, $parentId, $path);
        }
        $file = $this->resolver->resolve($organizationId, $parentType, $parentId, $path);
        $fileProjectId = AssistantNativeFileRegistry::supports($parentType) ? AssistantNativeFileRegistry::adapter($parentType)?->projectId($file)
            : ($file->fileable?->project_id ?? ($parentType === 'project' ? $parentId : null));
        if ($projectId !== null && (int) $fileProjectId !== $projectId) {
            throw new RuntimeException('ai_assistant_document_project_invalid');
        }

        return $this->registerFile($file, $actor);
    }

    public function registerFile(File $file, ?User $actor = null): AIAssistantDocument
    {
        $operations = app(AssistantOperationsNativeFileAdapter::class);
        if ($operations->isNativeFile($file)) {
            $mapping = AssistantNativeDocumentRegistry::fileMapping($file);
            if ($mapping === null) { throw new RuntimeException('ai_assistant_document_file_changed'); }
            $document = $actor === null
                ? $operations->mapForIndexing((int) $file->organization_id, $mapping['source_id'], $mapping['type'])
                : $operations->map($actor, (int) $file->organization_id, $mapping['type'], $mapping['parent_id'], (string) $file->path);
            if ($document === null) { throw new RuntimeException('ai_assistant_document_access_denied'); }
            return $document;
        }
        $parentType = $this->resolver->parentType($file);
        if (AssistantNativeFileRegistry::supports($parentType)) {
            if ($actor !== null) {
                AssistantNativeFileRegistry::adapter($parentType)?->assertReadable($actor, (int) $file->organization_id, $file);
            } else {
                AssistantNativeFileRegistry::assertIndexable($file, $parentType);
            }
        }
        if ($file->disk !== 's3') {
            throw new RuntimeException('ai_assistant_document_file_invalid');
        }
        $content = $this->read($file->path, (int) $file->organization_id);
        if ($parentType === 'design_artifact_version') {
            $expected = (string) ($file->additional_info['design_source_sha256'] ?? '');
            if ($expected !== '' && ! hash_equals($expected, hash('sha256', $content))) {
                throw new RuntimeException('ai_assistant_document_checksum_invalid');
            }
        }
        if (AssistantNativeFileRegistry::supports($parentType)) {
            $expected = (string) ($file->additional_info['native_source_sha256'] ?? '');
            if ($expected === '' || ! hash_equals($expected, hash('sha256', $content))) {
                throw new RuntimeException('ai_assistant_document_checksum_invalid');
            }
        }
        $projectId = $parentType === 'project' ? (int) $file->fileable_id : $file->fileable?->project_id;
        if (AssistantNativeFileRegistry::supports($parentType)) {
            $projectId = AssistantNativeFileRegistry::adapter($parentType)?->projectId($file);
        }

        $document = AIAssistantDocument::query()->firstOrCreate(
            ['file_id' => $file->id, 'checksum' => hash('sha256', $content)],
            ['organization_id' => $file->organization_id, 'project_id' => $projectId, 'parent_entity_type' => $parentType,
                'parent_entity_id' => (string) $file->fileable_id, 'storage_path' => $file->path,
                'filename' => $file->original_name ?: $file->name, 'mime_type' => $file->mime_type,
                'size_bytes' => strlen($content), 'status' => AIAssistantDocument::STATUS_QUEUED, 'coverage_status' => 'pending'],
        );
        foreach (AIAssistantDocument::query()->where('file_id', $file->id)->where('checksum', '!=', $document->checksum)->get() as $obsolete) {
            $this->failOcr((int) $obsolete->id);
            $obsolete->delete();
        }
        if ($document->wasRecentlyCreated) {
            $reusable = AIAssistantDocument::query()->where('organization_id', $document->organization_id)
                ->where('parent_entity_type', $document->parent_entity_type)->where('parent_entity_id', $document->parent_entity_id)
                ->where('checksum', $document->checksum)->where('status', AIAssistantDocument::STATUS_READY)->where('id', '!=', $document->id)->first();
            if ($reusable !== null) {
                DB::transaction(function () use ($document, $reusable): void {
                    foreach (AIAssistantDocumentUnit::query()->where('document_id', $reusable->id)->orderBy('id')->get() as $unit) {
                        AIAssistantDocumentUnit::query()->create(['document_id' => $document->id, 'unit_type' => $unit->unit_type,
                            'unit_index' => $unit->unit_index, 'text' => $unit->text, 'provenance' => $unit->provenance,
                            'checksum' => $unit->checksum, 'confidence' => $unit->confidence]);
                    }
                    $document->update(['status' => AIAssistantDocument::STATUS_READY, 'coverage_status' => $reusable->coverage_status,
                        'extracted_text' => $reusable->extracted_text, 'processed_at' => now(),
                        'metadata' => array_merge($document->metadata ?? [], ['page_count' => $reusable->metadata['page_count'] ?? 1, 'reused_from_document_id' => $reusable->id])]);
                });
            }
        }

        return $document;
    }

    public function process(int $documentId): AIAssistantDocument
    {
        return Cache::lock('assistant-document:'.$documentId, 180)->block(5, function () use ($documentId): AIAssistantDocument {
            $document = AIAssistantDocument::query()->findOrFail($documentId);
            if ($document->status !== AIAssistantDocument::STATUS_QUEUED && $document->status !== AIAssistantDocument::STATUS_FAILED) {
                return $document;
            }
            $this->assertCurrentFile($document);
            $result = $this->extractor->extract($this->documentContent($document), $document->mime_type, $document->filename);
            DB::transaction(function () use ($document, $result): void {
                AIAssistantDocumentUnit::query()->where('document_id', $document->id)->delete();
                foreach ($result['units'] as $unit) {
                    AIAssistantDocumentUnit::query()->create(['document_id' => $document->id, 'unit_type' => $unit['type'],
                        'unit_index' => $unit['index'], 'text' => $unit['text'], 'provenance' => $unit['provenance'], 'checksum' => hash('sha256', $unit['text'])]);
                }
                $document->update(['status' => $result['status'], 'coverage_status' => $result['coverage'], 'extracted_text' => $result['text'],
                    'metadata' => array_merge($document->metadata ?? [], ['page_count' => $result['page_count'] ?? 1]), 'processed_at' => now(), 'last_error' => null]);
            });

            return $document->refresh();
        });
    }

    public function quoteOcr(User $actor, int $organizationId, AIAssistantDocument $document): array
    {
        $this->assertReadable($actor, $organizationId, $document);
        $this->assertOcrRequired($document);
        if (empty($document->metadata['ocr_request_id'])) {
            $document->update(['metadata' => array_merge($document->metadata ?? [], ['ocr_request_id' => (string) \Illuminate\Support\Str::uuid()])]);
        }
        $quote = $this->credits->quote(Organization::query()->findOrFail($organizationId), $actor, $this->ocrPayload($document));
        $document->update(['ocr_quote_id' => \App\Models\Credits\AICreditQuote::query()->where('public_id', $quote['quote_id'])->value('id')]);

        return $quote + ['request_id' => $document->metadata['ocr_request_id']];
    }

    public function status(User $actor, int $organizationId, AIAssistantDocument $document): AIAssistantDocument
    {
        $this->assertReadable($actor, $organizationId, $document);

        return $document;
    }

    public function confirmOcr(User $actor, int $organizationId, AIAssistantDocument $document, string $quoteId, string $requestId): AIAssistantDocument
    {
        return DB::transaction(function () use ($actor, $organizationId, $document, $quoteId, $requestId): AIAssistantDocument {
            $document = AIAssistantDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $this->assertReadable($actor, $organizationId, $document);
            $this->assertOwner($actor, $organizationId);
            if ($document->ocr_reservation_id !== null) {
                $reservation = AICreditReservation::query()->findOrFail($document->ocr_reservation_id);
                $existingQuoteId = \App\Models\Credits\AICreditQuote::query()->whereKey($reservation->ai_credit_quote_id)->value('public_id');
                if ($reservation->request_id === $requestId && (int) $reservation->user_id === (int) $actor->id && $existingQuoteId === $quoteId) {
                    return $document;
                }
                if ($reservation->status === 'reserved') {
                    throw new RuntimeException('ai_assistant_document_ocr_in_progress');
                }
            }
            $this->assertOcrRequired($document);
            $reservation = $this->credits->begin(Organization::query()->findOrFail($organizationId), $actor, $quoteId, $requestId, null, $this->ocrPayload($document));
            if ($reservation->status !== 'reserved') {
                throw new RuntimeException('ai_assistant_document_ocr_request_consumed');
            }
            $document->update(['ocr_reservation_id' => $reservation->id, 'ocr_approved_by' => $actor->id,
                'ocr_approved_at' => now(), 'status' => AIAssistantDocument::STATUS_OCR_APPROVED,
                'metadata' => array_merge($document->metadata ?? [], ['ocr_attempt' => 0])]);

            return $document->refresh();
        });
    }

    public function processOcr(int $documentId): AIAssistantDocument
    {
        return Cache::lock('assistant-document:'.$documentId, 180)->block(5, function () use ($documentId): AIAssistantDocument {
            $document = AIAssistantDocument::query()->findOrFail($documentId);
            if ($document->status === AIAssistantDocument::STATUS_READY) {
                return $document;
            }
            $reservation = AICreditReservation::query()->findOrFail($document->ocr_reservation_id);
            $actor = User::query()->findOrFail($document->ocr_approved_by);
            $this->assertReadable($actor, (int) $document->organization_id, $document);
            $this->assertOwner($actor, (int) $document->organization_id);
            if ($document->status !== AIAssistantDocument::STATUS_OCR_APPROVED || $reservation->status !== 'reserved'
                || (int) $reservation->organization_id !== (int) $document->organization_id || (int) $reservation->user_id !== (int) $actor->id) {
                throw new RuntimeException('ai_assistant_document_ocr_not_authorized');
            }
            $complete = $this->ocr->recognize($document, $reservation, $this->documentContent($document), function () use ($document, $actor): void {
                $this->assertReadable($actor, (int) $document->organization_id, $document);
                $this->assertOwner($actor, (int) $document->organization_id);
                if ((int) ($document->metadata['background_budget_minor'] ?? 0) > 0) {
                    $enabled = \App\BusinessModules\Features\AIAssistant\Models\AssistantDocumentSettings::query()
                        ->where('organization_id', $document->organization_id)->where('background_ocr_enabled', true)->where('approved_by', $actor->id)->exists();
                    if (! $enabled) {
                        throw new RuntimeException('ai_assistant_document_background_ocr_revoked');
                    }
                }
            });
            if (! $complete) {
                \App\Jobs\ProcessAssistantDocumentOcr::dispatch((int) $document->id)->afterCommit();
                return $document->refresh();
            }
            return DB::transaction(function () use ($document, $reservation): AIAssistantDocument {
                $units = AIAssistantDocumentUnit::query()->where('document_id', $document->id)->where('unit_type', 'ocr_page')->orderBy('unit_index')->get();
                $useful = $units->contains(static fn ($unit): bool => trim($unit->text) !== '');
                $budgetCharge = $this->credits->successfulCostMicroRub($reservation) > 0 ? $this->credits->calculatedChargeMinor($reservation) : 0;
                $this->credits->finalize($reservation, 0, $useful);
                $this->settleBackgroundBudget($document, $budgetCharge);
                AIAssistantDocumentUnit::query()->where('document_id', $document->id)->where('unit_type', '!=', 'ocr_page')->delete();
                $document->update(['status' => $useful ? AIAssistantDocument::STATUS_READY : AIAssistantDocument::STATUS_OCR_QUOTE_REQUIRED,
                    'coverage_status' => $useful ? 'ocr' : 'empty', 'extracted_text' => $units->pluck('text')->implode("\n"), 'processed_at' => now(), 'last_error' => $useful ? null : 'ocr_empty',
                    'metadata' => $useful ? $document->metadata : array_diff_key($document->metadata ?? [], ['ocr_request_id' => true])]);

                return $document->refresh();
            });
        });
    }

    public function failOcr(int $documentId): void
    {
        DB::transaction(function () use ($documentId): void {
            $document = AIAssistantDocument::query()->whereKey($documentId)->lockForUpdate()->first();
            if ($document === null) {
                return;
            }
            $reservation = AICreditReservation::query()->find($document->ocr_reservation_id);
            $budgetCharge = $reservation !== null && $this->credits->successfulCostMicroRub($reservation) > 0
                ? $this->credits->calculatedChargeMinor($reservation) : 0;
            if ($reservation !== null && $reservation->status === 'reserved') {
                $this->credits->finalize($reservation, 0, false);
            }
            $this->settleBackgroundBudget($document, $budgetCharge);
            if ($document->status === AIAssistantDocument::STATUS_READY) {
                return;
            }
            AIAssistantDocumentUnit::query()->where('document_id', $documentId)->where('unit_type', 'ocr_page')->delete();
            $document->update(['status' => AIAssistantDocument::STATUS_OCR_QUOTE_REQUIRED, 'coverage_status' => 'failed', 'last_error' => 'ocr_failed',
                'metadata' => array_diff_key($document->metadata ?? [], ['ocr_request_id' => true])]);
        });
    }

    public function fail(int $documentId): void
    {
        AIAssistantDocument::query()->whereKey($documentId)->whereIn('status', [AIAssistantDocument::STATUS_QUEUED, AIAssistantDocument::STATUS_PROCESSING])
            ->update(['status' => AIAssistantDocument::STATUS_FAILED, 'coverage_status' => 'failed', 'last_error' => 'extraction_failed']);
    }

    public function assertOwner(User $actor, int $organizationId): void
    {
        if (! $this->policy->belongsToOrganization($actor, $organizationId)
            || ! Organization::query()->findOrFail($organizationId)->users()->where('users.id', $actor->id)->wherePivot('is_owner', true)->wherePivot('is_active', true)->exists()) {
            throw new RuntimeException('ai_assistant_document_owner_required');
        }
    }

    private function settleBackgroundBudget(AIAssistantDocument $document, int $charged): void
    {
        $reserved = (int) ($document->metadata['background_budget_minor'] ?? 0);
        if ($reserved === 0) {
            return;
        }
        $settings = \App\BusinessModules\Features\AIAssistant\Models\AssistantDocumentSettings::query()
            ->where('organization_id', $document->organization_id)->lockForUpdate()->firstOrFail();
        $settings->update(['reserved_minor' => max(0, $settings->reserved_minor - $reserved), 'spent_minor' => $settings->spent_minor + min($charged, $reserved)]);
        $document->update(['metadata' => array_diff_key($document->metadata ?? [], ['background_budget_minor' => true])]);
    }

    private function assertOcrRequired(AIAssistantDocument $document): void
    {
        if ($document->status !== AIAssistantDocument::STATUS_OCR_QUOTE_REQUIRED) {
            throw new RuntimeException('ai_assistant_document_ocr_not_required');
        }
        if ((int) ($document->metadata['page_count'] ?? 1) > AssistantDocumentOcrClient::MAX_PAGES) {
            throw new RuntimeException('ai_assistant_document_ocr_page_limit');
        }
    }

    private function assertReadable(User $actor, int $organizationId, AIAssistantDocument $document): void
    {
        if ((int) $document->organization_id !== $organizationId) {
            throw new RuntimeException('ai_assistant_document_access_denied');
        }
        $native = AssistantNativeDocumentRegistry::forDocument($document);
        if ($native !== null) {
            $native->assertReadable($actor, $organizationId, $document);
            return;
        }
        $this->assertContentAccess($actor, $organizationId, $document->parent_entity_type, $document->parent_entity_id);
        $this->assertCurrentFile($document);
    }

    private function assertContentAccess(User $actor, int $organizationId, string $parentType, string|int $parentId): void
    {
        if (! $this->policy->canReadEntityContent($actor, $organizationId, $parentType, $parentId)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
        }
    }

    private function assertCurrentFile(AIAssistantDocument $document): void
    {
        $native = AssistantNativeDocumentRegistry::forDocument($document);
        if ($native !== null) {
            $native->assertCurrent($document);
            return;
        }
        $file = $this->resolver->resolve((int) $document->organization_id, $document->parent_entity_type, $document->parent_entity_id, $document->storage_path);
        if ((int) $file->id !== (int) $document->file_id) {
            throw new RuntimeException('ai_assistant_document_file_changed');
        }
        AssistantNativeFileRegistry::assertIndexable($file, (string) $document->parent_entity_type);
    }

    private function documentContent(AIAssistantDocument $document): string
    {
        $native = AssistantNativeDocumentRegistry::forDocument($document);
        if ($native !== null) {
            $actor = User::query()->find((int) ($document->metadata['native_actor_user_id'] ?? 0));
            if ($actor === null) { throw new RuntimeException('ai_assistant_document_access_denied'); }
            return $native->content($actor, (int) $document->organization_id, $document);
        }
        $content = $this->read($document->storage_path, (int) $document->organization_id);
        if (! hash_equals($document->checksum, hash('sha256', $content))) {
            throw new RuntimeException('ai_assistant_document_checksum_changed');
        }

        return $content;
    }

    private function read(string $path, int $organizationId): string
    {
        if (! str_starts_with($path, 'org-'.$organizationId.'/')) {
            throw new RuntimeException('ai_assistant_document_path_invalid');
        }
        $stream = $this->files->readCurrentBounded($path, 10, self::MAX_BYTES + 1);
        try {
            $content = stream_get_contents($stream, self::MAX_BYTES + 1);
        } finally {
            fclose($stream);
        }
        if (! is_string($content) || strlen($content) > self::MAX_BYTES) {
            throw new RuntimeException('ai_assistant_document_too_large');
        }

        return $content;
    }

    private function ocrPayload(AIAssistantDocument $document): array
    {
        return ['profile' => 'ocr', 'request_key' => $document->metadata['ocr_request_id'] ?? '', 'document_id' => $document->id,
            'checksum' => $document->checksum, 'size_bytes' => $document->size_bytes, 'page_count' => (int) ($document->metadata['page_count'] ?? 1)];
    }
}
