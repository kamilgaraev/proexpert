<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationExecutionActor;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationMutationPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Services\DocumentParsingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsResolver;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ReuseEstimateGenerationDocuments
{
    public function __construct(
        private EstimateGenerationMutationPolicy $policy,
        private DocumentParsingService $parsing,
        private DocumentGenerationReadinessService $readiness,
        private EffectiveSettingsResolver $settingsResolver,
        private EstimateGenerationActionAuthorization $authorizer,
    ) {}

    public function handle(
        EstimateGenerationSession $session,
        int $expectedVersion,
        int $sourceSessionId,
        User $user,
    ): UploadDocumentsResult {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('document_reuse_requires_operation_transaction_boundary');
        }
        $batch = new UnpublishedDocumentObjectBatch((int) $session->organization_id, (int) $session->project_id, (int) $session->id);
        $created = collect();
        try {
            return DB::transaction(function () use ($session, $expectedVersion, $sourceSessionId, $user, &$created, $batch): UploadDocumentsResult {
                $session = EstimateGenerationSession::query()->whereKey($session->getKey())
                    ->where('organization_id', $session->organization_id)->where('project_id', $session->project_id)
                    ->lockForUpdate()->firstOrFail();
                $this->authorizer->authorize($user, $session, 'estimate_generation.upload_documents');
                $this->policy->documents($session, $expectedVersion);
                if ($sourceSessionId === (int) $session->id) {
                    throw ValidationException::withMessages(['source_session_id' => 'estimate_generation_document_source_same_session']);
                }

                $sourceSession = EstimateGenerationSession::query()
                    ->whereKey($sourceSessionId)
                    ->where('organization_id', $session->organization_id)
                    ->where('project_id', $session->project_id)
                    ->first();
                if ($sourceSession === null) {
                    throw ValidationException::withMessages(['source_session_id' => 'estimate_generation_document_source_not_found']);
                }

                $sourceDocuments = $sourceSession->documents()
                    ->whereNotIn('status', ['ignored'])
                    ->whereNotNull('storage_path')
                    ->whereNotNull('checksum_sha256')
                    ->orderBy('id')
                    ->get();
                if ($sourceDocuments->isEmpty()) {
                    throw ValidationException::withMessages(['source_session_id' => 'estimate_generation_document_source_empty']);
                }

                $settings = $this->settingsResolver->forOperation(
                    AiOperationContext::deterministicId("reuse|{$session->id}|{$sourceSessionId}|{$expectedVersion}"),
                    (int) $session->organization_id,
                    (int) $session->id,
                );
                $existingDocuments = $session->documents()->get(['meta']);
                $existingSourceIds = $existingDocuments
                    ->map(static fn (EstimateGenerationDocument $document): int => (int) ($document->meta['reused_from_document_id'] ?? 0))
                    ->filter(static fn (int $documentId): bool => $documentId > 0)
                    ->all();
                $missingDocuments = $sourceDocuments
                    ->reject(static fn (EstimateGenerationDocument $document): bool => in_array((int) $document->id, $existingSourceIds, true))
                    ->values();
                if ($existingDocuments->count() + $missingDocuments->count() > $settings->maxFiles()) {
                    throw ValidationException::withMessages(['source_session_id' => 'estimate_generation_document_file_limit_exceeded']);
                }
                foreach ($missingDocuments as $document) {
                    (new DocumentFileAdmission)->stored($document, $settings);
                }

                $documents = $this->parsing->reuseDocuments($session, $missingDocuments, $user, $batch);
                $created = $documents;
                $this->authorizer->authorize($user, $session, 'estimate_generation.upload_documents');
                $session = $session->fresh(['documents']) ?? $session->load('documents');
                if ($documents->isNotEmpty()) {
                    $session->forceFill(['input_payload' => [...($session->input_payload ?? []),
                        ...EstimateGenerationExecutionActor::identity($user)]])->saveQuietly();
                }

                return new UploadDocumentsResult($documents, $this->readiness->evaluate($session)['summary']);
            }, 1);
        } catch (Throwable $exception) {
            $this->parsing->rollbackStoredDocuments($session, $created);
            app(UnpublishedDocumentCleanupJournal::class)->recordBatch($batch);
            throw $exception;
        }
    }
}
