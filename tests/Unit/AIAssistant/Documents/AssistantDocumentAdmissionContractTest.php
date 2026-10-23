<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Documents;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantDocumentProjection;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantSourceProjection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCorpus;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagRetriever;

final class AssistantDocumentAdmissionContractTest extends TestCase
{
    private function projection(): OrdinaryAssistantDocumentProjection
    {
        return (new \ReflectionClass(OrdinaryAssistantDocumentProjection::class))->newInstanceWithoutConstructor();
    }

    public function test_model_query_is_bounded_but_not_frozen_to_owner_selection(): void
    {
        $args = ['query' => 'КС-2 %_\\', 'project_id' => 7, 'source_types' => ['file_document'], 'limit' => 2, 'retrieval_mode' => 'lexical'];
        self::assertSame($args['query'], $this->projection()->arguments($args, 7)['query']);
        self::assertSame('Другая формулировка', $this->projection()->arguments(array_replace($args, ['query' => 'Другая формулировка']), 7)['query']);
        self::assertSame(['chunk_ids' => [2, 3], 'document_id' => 9, 'project_id' => 7],
            $this->projection()->selection(['project_id' => 7, 'document_id' => 9, 'chunk_ids' => [3, 2]], 7));
        self::assertNotContains(OrdinaryAssistantDocumentProjection::TOOL, OrdinaryAssistantSourceProjection::toolsForPolicy(OrdinaryAssistantSourceProjection::VERSION));
        self::assertSame([], OrdinaryAssistantSourceProjection::toolsForPolicy('forged'));
    }

    public function test_scoped_score_ignores_unapproved_content_tail_hits(): void
    {
        $retriever = (new \ReflectionClass(RagRetriever::class))->newInstanceWithoutConstructor();
        $corpus = (new \ReflectionClass(AssistantDocumentCorpus::class))->newInstanceWithoutConstructor();
        $score = new \ReflectionMethod(RagRetriever::class, 'lexicalScore');
        $row = (object) ['title' => 'Approved title', 'content' => str_repeat('X', 2400).' hiddentail'];
        self::assertSame(0, $score->invoke($retriever, $row, ['hiddentail'], $corpus));
        self::assertSame(1, $score->invoke($retriever, $row, ['hiddentail']));
    }

    public function test_scoped_score_ignores_unapproved_title_tail_hits(): void
    {
        $retriever = (new \ReflectionClass(RagRetriever::class))->newInstanceWithoutConstructor();
        $corpus = (new \ReflectionClass(AssistantDocumentCorpus::class))->newInstanceWithoutConstructor();
        $score = new \ReflectionMethod(RagRetriever::class, 'lexicalScore');
        $row = (object) ['title' => str_repeat('X', 200).' hiddentitle', 'content' => 'Approved content'];
        self::assertSame(0, $score->invoke($retriever, $row, ['hiddentitle'], $corpus));
        self::assertSame(1, $score->invoke($retriever, $row, ['hiddentitle']));
    }

    public function test_hidden_tail_cannot_promote_a_scoped_candidate_rank(): void
    {
        $retriever = (new \ReflectionClass(RagRetriever::class))->newInstanceWithoutConstructor();
        $corpus = (new \ReflectionClass(AssistantDocumentCorpus::class))->newInstanceWithoutConstructor();
        $score = new \ReflectionMethod(RagRetriever::class, 'lexicalScore');
        $rows = [(object) ['id' => 1, 'title' => 'Approved title', 'content' => 'бетон'],
            (object) ['id' => 2, 'title' => 'Approved title', 'content' => 'бетон '.str_repeat('X', 2394).' hiddentail']];
        usort($rows, fn (object $a, object $b): int => $score->invoke($retriever, $b, ['бетон', 'hiddentail'], $corpus)
            <=> $score->invoke($retriever, $a, ['бетон', 'hiddentail'], $corpus));
        self::assertSame([1, 2], array_column($rows, 'id'));
        self::assertSame(2, $score->invoke($retriever, $rows[1], ['бетон', 'hiddentail']));
    }

    #[DataProvider('invalidArguments')]
    public function test_native_contract_rejects_expansion_before_retrieval(array $changes): void
    {
        $this->expectException(AssistantEgressDenied::class);
        $this->projection()->arguments(array_replace(['query' => 'бетон', 'project_id' => 7,
            'source_types' => ['file_document'], 'limit' => 3, 'retrieval_mode' => 'lexical'], $changes), 7);
    }

    public static function invalidArguments(): iterable
    {
        yield 'null project' => [['project_id' => null]];
        yield 'foreign project' => [['project_id' => 8]];
        yield 'hybrid' => [['retrieval_mode' => 'hybrid']];
        yield 'other sources' => [['source_types' => ['project']]];
        yield 'metadata' => [['read_mode' => 'metadata']];
        yield 'cursor' => [['cursor' => 'forged']];
        yield 'direct document' => [['document_id' => 9]];
        yield 'client corpus' => [['chunk_ids' => [1]]];
        yield 'client grant' => [['document_rag' => true]];
        yield 'limit' => [['limit' => 4]];
        yield 'numeric limit' => [['limit' => '3']];
        yield 'long query' => [['query' => str_repeat('я', 201)]];
        yield 'empty query' => [['query' => '  ']];
    }

    #[DataProvider('invalidStoredDocumentReferences')]
    public function test_stored_document_entry_rejects_other_or_incomplete_proofs(array $changes): void
    {
        $guard = (new \ReflectionClass(\App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard::class))->newInstanceWithoutConstructor();
        $reference = ['egress_projection_version' => OrdinaryAssistantDocumentProjection::VERSION,
            'entity_type' => 'assistant_document', 'source_type' => 'file_document', 'content_scope' => 'unstructured',
            'organization_id' => 3, 'entity_id' => 4, 'document_id' => 4, 'project_id' => 5,
            'unit_id' => 6, 'source_id' => 7, 'chunk_id' => 8,
            'document_checksum' => 'document', 'unit_checksum' => 'unit', 'rag_source_checksum' => 'source'];
        self::assertFalse($guard->storedDocumentCurrent(new \App\Models\User(), 3, [array_replace($reference, $changes)]));
    }

    public static function invalidStoredDocumentReferences(): iterable
    {
        yield 'unknown version' => [['egress_projection_version' => 'other']];
        yield 'SQL policy' => [['egress_projection_version' => OrdinaryAssistantSourceProjection::VERSION]];
        yield 'other entity' => [['entity_type' => 'project']];
        yield 'other source' => [['source_type' => 'project']];
        yield 'structured' => [['content_scope' => 'structured']];
        yield 'other tenant' => [['organization_id' => 9]];
        yield 'mismatched document' => [['document_id' => 9]];
        foreach (['entity_id', 'document_id', 'project_id', 'unit_id', 'source_id', 'chunk_id',
            'document_checksum', 'unit_checksum', 'rag_source_checksum'] as $key) {
            yield 'missing '.$key => [[$key => null]];
        }
    }
}
