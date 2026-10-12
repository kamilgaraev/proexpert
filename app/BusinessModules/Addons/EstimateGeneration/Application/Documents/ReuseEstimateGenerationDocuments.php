<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationExecutionActor;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Services\DocumentParsingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsResolver;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class ReuseEstimateGenerationDocuments
{
    public function __construct(
        private DocumentParsingService $parsing,
        private DocumentGenerationReadinessService $readiness,
        private EffectiveSettingsResolver $settingsResolver,
        private DocumentAdmissionOperation $admission,
    ) {}

    public function handle(EstimateGenerationSession $session, int $expectedVersion, int $sourceSessionId,
        User $user, ?string $requestId = null): UploadDocumentsResult
    {
        return $this->admission->run($session, $expectedVersion, $user, $requestId, 'reuse',
            hash('sha256', (string) $sourceSessionId),
            function (EstimateGenerationSession $current) use ($sourceSessionId, $expectedVersion): PreparedDocumentAdmission {
                if ($sourceSessionId === (int) $current->id) {
                    throw ValidationException::withMessages(['source_session_id' => 'estimate_generation_document_source_same_session']);
                }
                $sourceSession = EstimateGenerationSession::query()->whereKey($sourceSessionId)
                    ->where('organization_id', $current->organization_id)->where('project_id', $current->project_id)->first();
                if ($sourceSession === null) {
                    throw ValidationException::withMessages(['source_session_id' => 'estimate_generation_document_source_not_found']);
                }
                $sourceDocuments = $sourceSession->documents()->whereNotIn('status', ['ignored'])->whereNotNull('storage_path')
                    ->whereNotNull('checksum_sha256')->orderBy('id')->get();
                if ($sourceDocuments->isEmpty()) {
                    throw ValidationException::withMessages(['source_session_id' => 'estimate_generation_document_source_empty']);
                }
                $settings = $this->settingsResolver->forOperation(
                    AiOperationContext::deterministicId("reuse|{$current->id}|{$sourceSessionId}|{$expectedVersion}"),
                    (int) $current->organization_id, (int) $current->id,
                );
                $existing = $current->documents()->get(['meta']);
                $sourceIds = $existing->map(static fn (EstimateGenerationDocument $document): int => (int) ($document->meta['reused_from_document_id'] ?? 0))->filter(static fn (int $id): bool => $id > 0)->all();
                $missing = $sourceDocuments->reject(static fn (EstimateGenerationDocument $document): bool => in_array((int) $document->id, $sourceIds, true))->values();
                if ($existing->count() + $missing->count() > $settings->maxFiles()) {
                    throw ValidationException::withMessages(['source_session_id' => 'estimate_generation_document_file_limit_exceeded']);
                }
                foreach ($missing as $document) {
                    (new DocumentFileAdmission)->stored($document, $settings);
                }

                return PreparedDocumentAdmission::reuse($current, $missing);
            },
            function (EstimateGenerationSession $current, PreparedDocumentAdmission $prepared, UnpublishedDocumentObjectBatch $batch) use ($user): UploadDocumentsResult {
                if ($prepared->sources === null) {
                    throw new \LogicException('document_reuse_prepared_sources_missing');
                }
                $documents = $this->parsing->reuseDocuments($current, $prepared->sources, $user, $batch, $prepared->objects);
                $current = $current->fresh(['documents']) ?? $current->load('documents');
                if ($documents->isNotEmpty()) {
                    $current->forceFill(['input_payload' => [...($current->input_payload ?? []),
                        ...EstimateGenerationExecutionActor::identity($user)]])->saveQuietly();
                }

                return new UploadDocumentsResult($documents, $this->readiness->evaluate($current)['summary']);
            });
    }
}
