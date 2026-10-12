<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationExecutionActor;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Services\DocumentParsingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsResolver;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class UploadEstimateGenerationDocuments
{
    public function __construct(
        private DocumentParsingService $parsing,
        private DocumentGenerationReadinessService $readiness,
        private EffectiveSettingsResolver $settingsResolver,
        private DocumentAdmissionOperation $admission,
    ) {}

    /** @param list<UploadedFile> $files */
    public function handle(EstimateGenerationSession $session, int $expectedVersion, array $files, User $user, ?string $requestId = null): UploadDocumentsResult
    {
        $manifest = array_map(static fn (UploadedFile $file): array => ['sha256' => hash_file('sha256', $file->getPathname()),
            'size' => $file->getSize(), 'name' => $file->getClientOriginalName(), 'mime' => $file->getClientMimeType()], $files);
        $hash = hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR));

        return $this->admission->run($session, $expectedVersion, $user, $requestId, 'upload', $hash,
            function (EstimateGenerationSession $current) use ($files, $expectedVersion): PreparedDocumentAdmission {
                $settings = $this->settingsResolver->forOperation(
                    AiOperationContext::deterministicId("upload|{$current->id}|{$expectedVersion}"),
                    (int) $current->organization_id, (int) $current->id,
                );
                if ($files === [] || count($files) > 10 || $current->documents()->count() + count($files) > $settings->maxFiles()) {
                    throw ValidationException::withMessages(['files' => trans_message('estimate_evaluation.document_file_limit_exceeded',
                        ['limit' => $settings->maxFiles()])]);
                }
                foreach ($files as $file) {
                    (new DocumentFileAdmission)->uploaded($file, $settings);
                }

                return PreparedDocumentAdmission::upload($current, $files);
            },
            function (EstimateGenerationSession $current, PreparedDocumentAdmission $prepared, UnpublishedDocumentObjectBatch $batch) use ($files, $user): UploadDocumentsResult {
                $documents = $this->parsing->storeParsedDocuments($current, $files, $user, $batch, $prepared->objects);
                $current = $current->fresh(['documents']) ?? $current->load('documents');
                if ($documents->isNotEmpty()) {
                    $current->forceFill(['input_payload' => [...($current->input_payload ?? []),
                        ...EstimateGenerationExecutionActor::identity($user)]])->saveQuietly();
                }

                return new UploadDocumentsResult($documents, $this->readiness->evaluate($current)['summary']);
            });
    }
}
