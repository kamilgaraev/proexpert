<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationMutationPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\DocumentParsingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use App\Models\User;
use Illuminate\Http\UploadedFile;

final class UploadEstimateGenerationDocuments
{
    public function __construct(
        private EstimateGenerationMutationPolicy $policy,
        private DocumentParsingService $parsing,
        private DocumentGenerationReadinessService $readiness,
    ) {}

    /** @param list<UploadedFile> $files */
    public function handle(EstimateGenerationSession $session, int $expectedVersion, array $files, User $user): UploadDocumentsResult
    {
        $this->policy->documents($session, $expectedVersion);
        $documents = $this->parsing->storeParsedDocuments($session, $files, $user);
        $session = $session->fresh(['documents']) ?? $session->load('documents');

        return new UploadDocumentsResult($documents, $this->readiness->evaluate($session)['summary']);
    }
}
