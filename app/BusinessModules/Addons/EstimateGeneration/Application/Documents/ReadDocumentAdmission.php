<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ReadDocumentAdmission
{
    public function __construct(private EstimateGenerationActionAuthorization $authorizer,
        private DocumentGenerationReadinessService $readiness) {}

    public function handle(EstimateGenerationSession $session, int $projectId, User $actor, string $requestId): DocumentAdmissionStatus
    {
        if (! Str::isUuid($requestId)) {
            throw new \InvalidArgumentException('document_admission_request_invalid');
        }
        $current = EstimateGenerationSession::query()->whereKey($session->id)
            ->where('organization_id', $actor->current_organization_id)->where('organization_id', $session->organization_id)
            ->where('project_id', $projectId)->where('project_id', $session->project_id)->firstOrFail();
        $this->authorizer->authorize($actor, $current, 'estimate_generation.view');
        $row = DB::table(DocumentAdmissionOperation::TABLE)->where('organization_id', $current->organization_id)
            ->where('project_id', $current->project_id)->where('session_id', $current->id)->where('user_id', $actor->id)
            ->where('request_id', $requestId)->first();
        if ($row === null || $row->status !== 'accepted') {
            return new DocumentAdmissionStatus($requestId, $row === null ? 'not_found' : (string) $row->status);
        }
        $ids = json_decode($row->document_ids, true, 512, JSON_THROW_ON_ERROR);
        $documents = EstimateGenerationDocument::query()->where('organization_id', $current->organization_id)
            ->where('project_id', $current->project_id)->where('session_id', $current->id)->whereIn('id', $ids)->orderBy('id')->get();
        if (count($ids) !== $documents->count()) {
            throw new DocumentAdmissionConflict('document_admission_receipt_unavailable');
        }
        $documents->each(static fn (EstimateGenerationDocument $document) => $document->setRelation('session', $current));

        return new DocumentAdmissionStatus($requestId, 'accepted', new UploadDocumentsResult($documents,
            $this->readiness->summary($current->documents()->get())));
    }
}
