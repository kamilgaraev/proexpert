<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Egress;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCorpus;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCoverageService;
use App\Models\User;

final readonly class OrdinaryAssistantDocumentProjection
{
    public const VERSION = 'ordinary-document-rag/1';
    public const TOOL = 'search_assistant_documents';

    public function __construct(private AssistantDataAccessPolicy $access, private AssistantSourceReferenceGuard $guard,
        private AssistantDocumentCoverageService $coverage) {}

    public function selection(array $arguments, int $projectId): array
    {
        if (array_diff(array_keys($arguments), ['project_id', 'document_id', 'chunk_ids']) !== []
            || ($arguments['project_id'] ?? null) !== $projectId || ! is_int($arguments['document_id'] ?? null)
            || $arguments['document_id'] < 1 || ! is_array($arguments['chunk_ids'] ?? null) || ! array_is_list($arguments['chunk_ids'])
            || $arguments['chunk_ids'] === [] || count($arguments['chunk_ids']) > 3
            || count(array_unique($arguments['chunk_ids'])) !== count($arguments['chunk_ids'])
            || count(array_filter($arguments['chunk_ids'], static fn (mixed $id): bool => is_int($id) && $id > 0)) !== count($arguments['chunk_ids'])) {
            throw new AssistantEgressDenied('scope_mismatch');
        }
        sort($arguments['chunk_ids']);
        ksort($arguments);
        return $arguments;
    }

    public function arguments(array $arguments, int $projectId): array
    {
        if (array_diff(array_keys($arguments), ['query', 'project_id', 'source_types', 'limit', 'retrieval_mode']) !== []
            || ($arguments['project_id'] ?? null) !== $projectId || ($arguments['source_types'] ?? null) !== ['file_document']
            || ($arguments['retrieval_mode'] ?? null) !== 'lexical' || ! is_int($arguments['limit'] ?? null)
            || $arguments['limit'] < 1 || $arguments['limit'] > 3 || ! is_string($arguments['query'] ?? null)
            || trim($arguments['query']) === '' || mb_strlen($arguments['query']) > 200 || strlen($arguments['query']) > 800) {
            throw new AssistantEgressDenied('scope_mismatch');
        }
        ksort($arguments);
        return $arguments;
    }

    public function corpus(User $actor, int $organizationId, int $projectId, array $selection): AssistantDocumentCorpus
    {
        $selection = $this->selection($selection, $projectId);
        return AssistantDocumentCorpus::capture($actor, $organizationId, $projectId, $selection['document_id'], $selection['chunk_ids'], $this->access, $this->guard);
    }

    public function capture(User $actor, int $organizationId, int $projectId, array $selection): array
    {
        $selection = $this->selection($selection, $projectId);
        $corpus = $this->corpus($actor, $organizationId, $projectId, $selection);
        $projected = $this->project($corpus, $corpus->results(), $actor, $organizationId);
        $provider = self::forProvider($projected);
        return ['proof' => ['tool' => self::TOOL, 'arguments' => $selection, 'projection_version' => self::VERSION,
            'source_versions' => array_map(self::referenceHash(...), array_values($corpus->references())),
            'projection_hash' => OrdinaryAssistantSourceProjection::hash($provider),
            'fragment_hashes' => array_map(OrdinaryAssistantSourceProjection::hash(...), $provider['data']['fragments'])], 'preview' => $provider, 'corpus' => $corpus];
    }

    public function project(AssistantDocumentCorpus $corpus, array $results, User $actor, int $organizationId): array
    {
        $corpus->assertActor($actor, $organizationId);
        $coverage = $this->coverage->coverageForCorpus($corpus, $actor, $organizationId);
        if (($coverage['complete'] ?? false) !== true || count($results) > 3) {
            throw new AssistantEgressDenied('source_stale');
        }
        $fragments = $references = [];
        foreach ($results as $result) {
            $chunkId = $result->metadata['chunk_id'] ?? null;
            $reference = $corpus->references()[$chunkId] ?? null;
            $content = $result->providerContent();
            $expected = is_int($chunkId) ? $corpus->result($chunkId) : null;
            if ($reference === null || $expected === null || ! is_string($content) || $content === ''
                || $content !== $expected->providerContent() || $result->title !== $expected->title || $result->projectId !== $corpus->projectId
                || $result->sourceType !== 'file_document' || (string) $result->entityId !== (string) $corpus->documentId) {
                throw new AssistantEgressDenied('source_unknown');
            }
            $fragments[] = ['document_id' => $corpus->documentId, 'unit_id' => $reference['unit_id'], 'chunk_id' => $chunkId,
                'chunk_index' => $reference['chunk_index'], 'title' => mb_substr($result->title, 0, AssistantDocumentCorpus::TITLE_CHARS), 'content' => mb_substr($content, 0, AssistantDocumentCorpus::CONTENT_CHARS),
                'content_range' => ['scope' => 'rag_chunk', 'offset' => 0, 'length' => min(mb_strlen($content), AssistantDocumentCorpus::CONTENT_CHARS),
                    'total_length' => mb_strlen($content), 'truncated' => mb_strlen($content) > AssistantDocumentCorpus::CONTENT_CHARS]];
            $references[] = $reference + ['egress_projection_version' => self::VERSION];
        }
        if (! $this->guard->fresh($actor, $organizationId, $references)) {
            throw new AssistantEgressDenied('source_stale');
        }
        $projected = ['status' => 'success', 'data' => ['project' => ['id' => $corpus->projectId],
            'kind' => 'untrusted_search_results', 'coverage_scope' => 'approved_fragment_universe', 'search_complete' => false,
            'approved_fragments' => count($corpus->chunkIds()), 'fragments' => $fragments],
            'source_refs' => $references, 'egress_projection_version' => self::VERSION];
        if (strlen(json_encode(self::forProvider($projected), JSON_THROW_ON_ERROR)) > 65536) {
            throw new AssistantEgressDenied('scope_mismatch');
        }
        return $projected;
    }

    public function subset(array|string $result, array $source, User $actor, int $organizationId, int $projectId): array
    {
        if (! is_array($result) || array_diff(array_keys($result), ['status', 'data', 'source_refs', 'egress_projection_version']) !== []
            || ($result['status'] ?? null) !== 'success' || ($result['egress_projection_version'] ?? null) !== self::VERSION
            || ! is_array($result['data'] ?? null) || ! is_array($result['source_refs'] ?? null)) {
            throw new AssistantEgressDenied('source_unknown');
        }
        $data = $result['data'];
        if (array_diff(array_keys($data), ['project', 'kind', 'coverage_scope', 'search_complete', 'approved_fragments', 'fragments']) !== []
            || ($data['project'] ?? null) !== ['id' => $projectId] || ($data['kind'] ?? null) !== 'untrusted_search_results'
            || ($data['coverage_scope'] ?? null) !== 'approved_fragment_universe' || ($data['search_complete'] ?? null) !== false
            || ($data['approved_fragments'] ?? null) !== count($source['fragment_hashes']) || ! is_array($data['fragments'] ?? null)
            || ! array_is_list($data['fragments']) || count($data['fragments']) > 3 || count($result['source_refs']) !== count($data['fragments'])) {
            throw new AssistantEgressDenied('scope_mismatch');
        }
        $seen = [];
        foreach ($data['fragments'] as $index => $fragment) {
            if (! is_array($fragment)) { throw new AssistantEgressDenied('source_unknown'); }
            $hash = OrdinaryAssistantSourceProjection::hash($fragment);
            $position = array_search($hash, $source['fragment_hashes'], true);
            $reference = $result['source_refs'][$index] ?? null;
            if ($position === false || isset($seen[$hash]) || ! is_array($reference)
                || ($reference['project_id'] ?? null) !== $projectId || ($reference['organization_id'] ?? null) !== $organizationId
                || self::referenceHash($reference) !== $source['source_versions'][$position]
                || ($reference['chunk_id'] ?? null) !== ($fragment['chunk_id'] ?? null)) {
                throw new AssistantEgressDenied('source_stale');
            }
            $seen[$hash] = true;
        }
        if (! $this->guard->fresh($actor, $organizationId, $result['source_refs'])) {
            throw new AssistantEgressDenied('source_stale');
        }
        return $result;
    }

    public static function forProvider(array $projected): array
    {
        $projected['source_refs'] = array_map(static fn (array $reference): array => array_intersect_key($reference,
            array_flip(['entity_type', 'entity_id', 'project_id', 'document_id', 'unit_id', 'chunk_id', 'chunk_index'])), $projected['source_refs']);
        return $projected;
    }

    public static function referenceHash(array $reference): string
    {
        unset($reference['fetched_at'], $reference['egress_projection_version']);
        return OrdinaryAssistantSourceProjection::hash($reference);
    }
}
