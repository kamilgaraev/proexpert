<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagSearchResult;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class AssistantDocumentCorpus
{
    public const CONTENT_CHARS = 2400;
    public const TITLE_CHARS = 200;

    private function __construct(
        public int $actorId,
        public int $organizationId,
        public int $projectId,
        public int $documentId,
        private array $results,
        private array $references,
    ) {}

    public static function capture(User $actor, int $organizationId, int $projectId, int $documentId, array $chunkIds,
        AssistantDataAccessPolicy $access, AssistantSourceReferenceGuard $guard): self
    {
        if ($projectId < 1 || ! array_is_list($chunkIds) || $chunkIds === [] || count($chunkIds) > 3 || count(array_unique($chunkIds)) !== count($chunkIds)
            || count(array_filter($chunkIds, static fn (mixed $id): bool => is_int($id) && $id > 0)) !== count($chunkIds)) {
            throw new AssistantEgressDenied('scope_mismatch');
        }
        return $access->withCurrentChecks($actor, $organizationId, function () use ($actor, $organizationId, $projectId, $documentId, $chunkIds, $access, $guard): self {
            $document = $access->accessibleDocuments($actor, $organizationId,
                AIAssistantDocument::query()->whereKey($documentId)->where('project_id', $projectId))->first();
            if (! $document instanceof AIAssistantDocument || $document->status !== AIAssistantDocument::STATUS_READY || $document->processed_at === null
                || ! in_array($document->coverage_status, ['full', 'ocr'], true)) {
                throw new AssistantEgressDenied('source_stale');
            }
            $rows = DB::table('ai_rag_chunks as c')->join('ai_rag_sources as s', 's.id', '=', 'c.source_id')
                ->where('c.organization_id', $organizationId)->where('s.organization_id', $organizationId)
                ->where('c.project_id', $projectId)->where('s.project_id', $projectId)
                ->where('s.source_type', 'file_document')->where('s.entity_type', 'assistant_document')->where('s.entity_id', (string) $documentId)
                ->whereIn('c.id', $chunkIds)->orderBy('c.id')->get(['c.id', 'c.chunk_index', 'c.content', 'c.metadata', 's.id as source_id', 's.checksum', 's.source_version', 's.title']);
            if ($rows->count() !== count($chunkIds)) {
                throw new AssistantEgressDenied('scope_mismatch');
            }
            $results = $references = [];
            foreach ($rows as $row) {
                $metadata = is_string($row->metadata) ? json_decode($row->metadata, true, 512, JSON_THROW_ON_ERROR) : (array) $row->metadata;
                if (! is_int($metadata['unit_id'] ?? null) || ! is_string($metadata['unit_checksum'] ?? null)) {
                    throw new AssistantEgressDenied('source_unknown');
                }
                $reference = array_intersect_key($metadata, array_flip(['unit_id', 'unit_checksum', 'unit_index', 'unit_type']));
                $reference += ['source_type' => 'file_document', 'entity_type' => 'assistant_document', 'entity_id' => $documentId,
                    'organization_id' => $organizationId, 'project_id' => $projectId, 'document_id' => $documentId,
                    'document_checksum' => $document->checksum, 'source_id' => (int) $row->source_id,
                    'rag_source_checksum' => $row->checksum, 'source_version' => $row->source_version,
                    'chunk_id' => (int) $row->id, 'chunk_index' => (int) $row->chunk_index,
                    'content_scope' => 'unstructured', 'fetched_at' => now()->toISOString()];
                $references[(int) $row->id] = $reference;
                $results[(int) $row->id] = new RagSearchResult('file_document', 'assistant_document', $documentId, $projectId,
                    (string) $row->title, '', 0.0, $reference, $document->updated_at, (string) $row->content);
            }
            if (! $guard->fresh($actor, $organizationId, array_values($references))) {
                throw new AssistantEgressDenied('source_stale');
            }
            return new self((int) $actor->id, $organizationId, $projectId, $documentId, $results, $references);
        }, true);
    }

    public function assertActor(User $actor, int $organizationId): void
    {
        if ((int) $actor->id !== $this->actorId || $organizationId !== $this->organizationId) {
            throw new AssistantEgressDenied('scope_mismatch');
        }
    }

    public function chunkIds(): array
    {
        return array_keys($this->references);
    }

    public function sourceIds(): array
    {
        return array_values(array_unique(array_column($this->references, 'source_id')));
    }

    public function results(): array
    {
        return array_values($this->results);
    }

    public function result(int $chunkId): ?RagSearchResult
    {
        return $this->results[$chunkId] ?? null;
    }

    public function references(): array
    {
        return $this->references;
    }
}
