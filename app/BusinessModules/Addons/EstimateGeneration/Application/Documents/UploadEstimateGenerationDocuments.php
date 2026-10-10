<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationExecutionActor;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationMutationPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Services\DocumentParsingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsResolver;
use App\Models\User;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

final class UploadEstimateGenerationDocuments
{
    public function __construct(
        private EstimateGenerationMutationPolicy $policy,
        private DocumentParsingService $parsing,
        private DocumentGenerationReadinessService $readiness,
        private EffectiveSettingsResolver $settingsResolver,
        private EstimateGenerationActionAuthorization $authorizer,
    ) {}

    /** @param list<UploadedFile> $files */
    public function handle(EstimateGenerationSession $session, int $expectedVersion, array $files, User $user): UploadDocumentsResult
    {
        $created = collect();
        try {
            return DB::transaction(function () use ($session, $expectedVersion, $files, $user, &$created): UploadDocumentsResult {
                $session = EstimateGenerationSession::query()->whereKey($session->getKey())
                    ->where('organization_id', $session->organization_id)->where('project_id', $session->project_id)
                    ->lockForUpdate()->firstOrFail();
                $this->authorizer->authorize($user, $session, 'estimate_generation.upload_documents');
                $this->policy->documents($session, $expectedVersion);
                $settings = $this->settingsResolver->forOperation(
                    AiOperationContext::deterministicId("upload|{$session->id}|{$expectedVersion}"),
                    (int) $session->organization_id,
                    (int) $session->id,
                );
                $existingFiles = $session->documents()->count();
                if ($existingFiles + count($files) > $settings->maxFiles()) {
                    throw new DomainException('estimate_generation_document_file_limit_exceeded');
                }
                foreach ($files as $file) {
                    $extension = strtolower($file->getClientOriginalExtension());
                    if (! $settings->allowsFormat($extension)) {
                        throw new DomainException('estimate_generation_document_format_disabled');
                    }
                }
                $documents = $this->parsing->storeParsedDocuments($session, $files, $user);
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
            throw $exception;
        }
    }
}
