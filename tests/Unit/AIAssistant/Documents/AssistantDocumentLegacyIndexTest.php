<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Documents;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;
use Tests\Unit\AIAssistant\UsesAssistantUnitTranslations;

final class AssistantDocumentLegacyIndexTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_legacy_source_remains_current_only_when_its_exact_checksum_and_chunk_content_match(): void
    {
        [$indexer, $source, $current] = $this->fixture();
        self::assertTrue($indexer->matchesLexicalSource($source, $current));
        $changed = new RagChunkData(7, 9, 'file_document', 'assistant_document', '11', 'Документ', 'Новый текст',
            array_replace($current->metadata, ['unit_checksum' => hash('sha256', 'Новый текст')]), $current->updatedAt);
        self::assertFalse($indexer->matchesLexicalSource($source, $changed));
        $source->checksum = str_repeat('c', 64);
        self::assertFalse($indexer->matchesLexicalSource($source, $current));
    }

    public function test_modern_unit_proof_cannot_fall_back_to_legacy_when_checksum_or_provenance_changes(): void
    {
        [$indexer, $source, $current] = $this->fixture();
        $source->metadata = array_replace($source->metadata, ['unit_checksum' => str_repeat('c', 64)]);
        self::assertFalse($indexer->matchesLexicalSource($source, $current));
        [$indexer, $source, $current] = $this->fixture();
        $source->metadata = array_replace($source->metadata, ['provenance' => ['page' => 99]]);
        self::assertFalse($indexer->matchesLexicalSource($source, $current));
    }

    public function test_stored_content_hash_and_scope_are_required_even_with_a_valid_source_checksum(): void
    {
        [$indexer, $source, $current] = $this->fixture();
        $source->getRelation('chunks')[0]->content = 'Подмена';
        self::assertFalse($indexer->matchesLexicalSource($source, $current));
        [$indexer, $source, $current] = $this->fixture();
        $source->project_id = 10;
        self::assertFalse($indexer->matchesLexicalSource($source, $current));
    }

    public function test_lexical_content_proof_uses_stored_embedding_dimensions_after_profile_configuration_changes(): void
    {
        [, $source, $current] = $this->fixture();
        $provider = $this->createMock(RagEmbeddingProviderInterface::class);
        $provider->method('provider')->willReturn('retired-provider');
        $provider->method('model')->willReturn('retired-model');
        $provider->method('dimensions')->willReturn(3);
        $provider->expects(self::never())->method('embed');
        self::assertTrue((new RagIndexer($provider, new RagSourceRegistry([])))->matchesLexicalSource($source, $current));
    }

    private function fixture(): array
    {
        $provider = $this->createMock(RagEmbeddingProviderInterface::class);
        $provider->method('provider')->willReturn('retired-provider');
        $provider->method('model')->willReturn('retired-model');
        $provider->method('dimensions')->willReturn(2);
        $provider->expects(self::never())->method('embed');
        $indexer = new RagIndexer($provider, new RagSourceRegistry([]));
        $metadata = ['document_id' => 11, 'file_id' => 15, 'unit_id' => 22, 'parent_entity_type' => 'project',
            'parent_entity_id' => '9', 'provenance' => ['page' => 7], 'coverage_status' => 'full', 'checksum' => str_repeat('a', 64)];
        $timestamp = new DateTimeImmutable('2026-10-11T10:00:00+00:00');
        $legacy = new RagChunkData(7, 9, 'file_document', 'assistant_document', '11', 'Документ', 'Текст', $metadata, $timestamp);
        $identity = $indexer->coverageIdentity($legacy);
        $source = new RagSource;
        $source->setDateFormat('Y-m-d H:i:s');
        $source->setRawAttributes(['id' => 31, 'organization_id' => 7, 'project_id' => 9, 'identity_project_id' => 9,
            'identity_part_key' => '22', 'source_type' => 'file_document', 'entity_type' => 'assistant_document', 'entity_id' => '11',
            'title' => 'Документ', 'checksum' => $identity['checksum'], 'source_version' => $timestamp->format(DATE_ATOM),
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'indexed_at' => '2026-10-11 10:00:01']);
        $stored = new RagChunk;
        $stored->setRawAttributes(['id' => 41, 'source_id' => 31, 'organization_id' => 7, 'project_id' => 9,
            'chunk_index' => 0, 'content' => 'Текст', 'content_hash' => hash('sha256', 'Текст'),
            'metadata' => json_encode($metadata + ['chunk_index' => 0, 'chunk_count' => 1], JSON_THROW_ON_ERROR),
            'embedding_provider' => 'retired-provider', 'embedding_model' => 'retired-model', 'embedding_dimensions' => 2]);
        $source->setRelation('chunks', new Collection([$stored]));
        $current = new RagChunkData(7, 9, 'file_document', 'assistant_document', '11', 'Документ', 'Текст',
            $metadata + ['unit_checksum' => hash('sha256', 'Текст'), 'unit_index' => 7, 'unit_type' => 'page'], $timestamp);
        return [$indexer, $source, $current];
    }
}
