<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AssistantEgressCohort;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantDocumentProjection;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEnrollmentService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final readonly class AssistantDocumentCandidateService
{
    private const DOCUMENT_LIMIT = 10;
    private const CHUNK_LIMIT = 3;
    private const DOCUMENT_COLUMNS = ['id', 'parent_entity_type', 'parent_entity_id', 'filename', 'status', 'coverage_status', 'processed_at'];

    public function __construct(private AssistantDataAccessPolicy $access, private OrdinaryAssistantEnrollmentService $enrollments) {}

    public function page(User $actor, int $organizationId, ?int $documentId = null, ?int $afterId = null): array
    {
        if (($documentId !== null && $documentId < 1) || ($afterId !== null && $afterId < 1)) {
            throw new AssistantEgressDenied('scope_mismatch');
        }

        return $this->access->withCurrentChecks($actor, $organizationId, function () use ($actor, $organizationId, $documentId, $afterId): array {
            $this->enrollments->assertOwner($actor, $organizationId);
            $cohortId = AssistantEgressCohort::query()->where('organization_id', $organizationId)->where('actor_id', $actor->id)
                ->where('status', 'active')->where('expires_at', '>', now())->value('id');
            if (! is_string($cohortId)) {
                throw new AssistantEgressDenied('access_denied');
            }
            $cohort = $this->enrollments->cohort($cohortId, $organizationId);
            if ((int) $cohort->actor_id !== (int) $actor->id
                || ! in_array(OrdinaryAssistantDocumentProjection::TOOL, $cohort->allowed_tools, true)
                || ! $this->access->canReadDomain($actor, $organizationId, 'assistant')
                || ! $this->access->canReadEntity($actor, $organizationId, 'project', (int) $cohort->project_id)) {
                throw new AssistantEgressDenied('access_denied');
            }
            $projectId = (int) $cohort->project_id;
            $query = $this->documents($actor, $organizationId, $projectId);
            if ($documentId !== null) {
                $document = $query->whereKey($documentId)->first(self::DOCUMENT_COLUMNS);
                if (! $document instanceof AIAssistantDocument || ! $this->readable($actor, $organizationId, $document)) {
                    throw new AssistantEgressDenied('access_denied');
                }
                $chunks = $this->chunks($organizationId, $projectId, $documentId)
                    ->when($afterId !== null, static fn (QueryBuilder $query): QueryBuilder => $query->where('c.id', '>', $afterId))
                    ->orderBy('c.id')->limit(self::CHUNK_LIMIT)->get(['c.id as chunk_id', 'c.chunk_index', 'u.unit_type', 'u.unit_index']);
                $items = $chunks->map(static fn (object $row): array => ['chunk_id' => (int) $row->chunk_id,
                    'chunk_index' => (int) $row->chunk_index, 'unit_type' => (string) $row->unit_type, 'unit_index' => (int) $row->unit_index])->all();

                return $this->response('chunks', $items, count($items) === self::CHUNK_LIMIT, false, 'chunk_id') + ['document_id' => $documentId];
            }
            $documents = $query->when($afterId !== null, static fn (Builder $query): Builder => $query->where('id', '>', $afterId))
                ->orderBy('id')->limit(self::DOCUMENT_LIMIT)->get(self::DOCUMENT_COLUMNS);
            $items = [];
            $denied = false;
            foreach ($documents as $document) {
                if (! $this->readable($actor, $organizationId, $document)) {
                    $denied = true;
                    continue;
                }
                $items[] = ['document_id' => (int) $document->id, 'filename' => mb_substr((string) $document->filename, 0, 200),
                    'status' => (string) $document->status, 'coverage_status' => (string) $document->coverage_status,
                    'processed_at' => $document->processed_at?->toISOString()];
            }

            return $this->response('documents', $items, $documents->count() === self::DOCUMENT_LIMIT || $denied, $denied, 'document_id');
        }, true);
    }

    private function documents(User $actor, int $organizationId, int $projectId): Builder
    {
        $query = AIAssistantDocument::query()->where('project_id', $projectId)->where('status', AIAssistantDocument::STATUS_READY)
            ->whereNotNull('processed_at')->whereIn('coverage_status', ['full', 'ocr']);

        return $this->access->accessibleDocuments($actor, $organizationId, $query)->select(self::DOCUMENT_COLUMNS);
    }

    private function readable(User $actor, int $organizationId, AIAssistantDocument $document): bool
    {
        return $this->access->canReadEntityContent($actor, $organizationId, (string) $document->parent_entity_type, (string) $document->parent_entity_id);
    }

    private function chunks(int $organizationId, int $projectId, int $documentId): QueryBuilder
    {
        return DB::table('ai_rag_chunks as c')->join('ai_rag_sources as s', 's.id', '=', 'c.source_id')
            ->join('ai_assistant_document_units as u', static function ($join): void {
                $join->whereRaw("c.metadata->>'unit_id' = CAST(u.id AS TEXT)")
                    ->whereRaw("s.metadata->>'unit_id' = CAST(u.id AS TEXT)")
                    ->whereRaw("c.metadata->>'unit_checksum' = u.checksum")
                    ->whereRaw("s.metadata->>'unit_checksum' = u.checksum");
            })
            ->where('u.document_id', $documentId)->where('c.organization_id', $organizationId)->where('s.organization_id', $organizationId)
            ->where('c.project_id', $projectId)->where('s.project_id', $projectId)->where('s.source_type', 'file_document')
            ->where('s.entity_type', 'assistant_document')->where('s.entity_id', (string) $documentId);
    }

    private function response(string $kind, array $items, bool $scanLimited, bool $continuationUnknown, string $identity): array
    {
        return ['kind' => $kind, 'coverage_scope' => 'candidate_page', 'preview_required' => true, 'items' => $items,
            'next_after_id' => $scanLimited && $items !== [] ? $items[array_key_last($items)][$identity] : null,
            'scan_limited' => $scanLimited, 'continuation_unknown' => $continuationUnknown];
    }
}
