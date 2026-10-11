<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\FileRagSource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class AssistantDocumentIndexCompatibility
{
    private const PACKET_CHARACTERS = 2_000_000;

    public function __construct(private RagIndexer $indexer) {}

    public function verifiedMetadata(array $sources, ?callable $checkpoint = null, bool $semantic = false, array $candidateChunkIds = []): array
    {
        $verified = [];
        foreach (array_chunk($sources, 50) as $candidates) {
            $checkpoint?->__invoke();
            $documentIds = [];
            $unitIds = [];
            $organizationIds = [];
            foreach ($candidates as $source) {
                if (! $source instanceof RagSource || $source->source_type !== 'file_document' || $source->entity_type !== 'assistant_document') { continue; }
                $documentIds[] = (int) $source->entity_id;
                $unitIds[] = (int) $source->identity_part_key;
                $organizationIds[] = $source->organization_id;
            }
            if ($documentIds === []) { continue; }
            $documents = AIAssistantDocument::query()->whereIn('organization_id', $organizationIds)->whereKey($documentIds)
                ->where('status', AIAssistantDocument::STATUS_READY)->get(['id', 'organization_id', 'project_id', 'file_id',
                    'parent_entity_type', 'parent_entity_id', 'filename', 'checksum', 'coverage_status', 'processed_at', 'updated_at'])->keyBy('id');
            $checkpoint?->__invoke();
            $units = AIAssistantDocumentUnit::query()->whereKey($unitIds)->whereIn('document_id', $documentIds)
                ->select(['id', 'document_id'])->selectRaw('CHAR_LENGTH(text) AS text_characters')->get()->keyBy('id');
            $sizes = DB::table('ai_rag_chunks')->whereIn('source_id', array_map(static fn (RagSource $source): int => (int) $source->id, $candidates))
                ->select('source_id')->selectRaw('SUM(CHAR_LENGTH(content)) AS text_characters, COUNT(*) AS chunk_count')->groupBy('source_id')->get()->keyBy('source_id');
            $packet = [];
            $characters = 0;
            foreach ($candidates as $source) {
                $document = $documents->get((int) $source->entity_id);
                $unit = $units->get((int) $source->identity_part_key);
                $size = $sizes->get((int) $source->id);
                if (! $document instanceof AIAssistantDocument || ! $unit instanceof AIAssistantDocumentUnit || $size === null
                    || $unit->document_id !== (int) $document->id || $document->processed_at === null
                    || (int) $unit->text_characters <= 0 || (int) $size->chunk_count > 5000) { continue; }
                $weight = max((int) $unit->text_characters, (int) $size->text_characters);
                if ($weight > self::PACKET_CHARACTERS) { continue; }
                if ($packet !== [] && $characters + $weight > self::PACKET_CHARACTERS) {
                    $verified += $this->verifyPacket($packet, $documents, $checkpoint, $semantic, $candidateChunkIds);
                    $packet = [];
                    $characters = 0;
                }
                $packet[] = $source;
                $characters += $weight;
            }
            if ($packet !== []) { $verified += $this->verifyPacket($packet, $documents, $checkpoint, $semantic, $candidateChunkIds); }
        }
        return $verified;
    }

    private function verifyPacket(array $sources, Collection $documents, ?callable $checkpoint, bool $semantic, array $candidateChunkIds): array
    {
        $checkpoint?->__invoke();
        $units = AIAssistantDocumentUnit::query()->whereKey(array_map(static fn (RagSource $source): int => (int) $source->identity_part_key, $sources))
            ->get(['id', 'document_id', 'unit_index', 'unit_type', 'checksum', 'provenance', 'text'])->keyBy('id');
        $query = RagChunk::query()->whereIn('source_id', array_map(static fn (RagSource $source): int => (int) $source->id, $sources))
            ->select(['id', 'source_id', 'organization_id', 'project_id', 'chunk_index', 'content', 'content_hash', 'metadata', 'embedding_provider', 'embedding_model']);
        if (DB::connection()->getDriverName() === 'pgsql') { $query->selectRaw('vector_dims(embedding) AS embedding_dimensions'); }
        else { $query->addSelect('embedding'); }
        $chunks = $query->get()->groupBy('source_id');
        $verified = [];
        foreach ($sources as $source) {
            $checkpoint?->__invoke();
            $document = $documents->get((int) $source->entity_id);
            $unit = $units->get((int) $source->identity_part_key);
            $sourceChunks = $chunks->get((int) $source->id);
            if (! $document instanceof AIAssistantDocument || ! $unit instanceof AIAssistantDocumentUnit
                || $unit->document_id !== (int) $document->id || ! $sourceChunks instanceof Collection) { continue; }
            if ($semantic && $sourceChunks->contains(static fn (RagChunk $chunk): bool => (int) $chunk->getAttribute('embedding_dimensions') <= 0)) { continue; }
            $data = FileRagSource::chunkForUnit($document, $unit);
            $previousChunks = $source->getRelations()['chunks'] ?? null;
            $source->setRelation('chunks', $sourceChunks);
            try { $matches = $this->indexer->matchesLexicalSource($source, $data); }
            finally {
                if ($previousChunks !== null) { $source->setRelation('chunks', $previousChunks); }
                else { $source->unsetRelation('chunks'); }
            }
            if (! $matches) { continue; }
            $metadata = $data->metadata;
            if ($candidateChunkIds !== []) {
                $metadata['_verified_chunk_hashes'] = [];
                foreach ($sourceChunks as $stored) {
                    if (in_array((int) $stored->id, $candidateChunkIds, true)) { $metadata['_verified_chunk_hashes'][(int) $stored->id] = hash('sha256', (string) $stored->content); }
                }
            }
            $verified[(int) $source->id] = $metadata;
        }
        return $verified;
    }
}
