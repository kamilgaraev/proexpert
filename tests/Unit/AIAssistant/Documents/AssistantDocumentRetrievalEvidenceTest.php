<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Documents;

use App\BusinessModules\Features\AIAssistant\Actions\Domains\SearchAssistantDocumentsTool;
use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagSearchResult;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentUnitReader;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagPromptContextBuilder;
use PHPUnit\Framework\TestCase;
use Tests\Unit\AIAssistant\UsesAssistantUnitTranslations;

final class AssistantDocumentRetrievalEvidenceTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_ready_and_scanned_document_does_not_prove_current_unit_index_coverage(): void
    {
        $coverage = $this->completeCoverage();
        self::assertFalse(AssistantDocumentCoverageService::isCompleteForAnswer($coverage));
        $coverage['retrieval_coverage'] = ['complete' => true, 'expected_units' => 2, 'current_units' => 2,
            'partial_documents' => 0, 'fresh' => true, 'mode' => 'lexical'];
        self::assertTrue(AssistantDocumentCoverageService::isCompleteForAnswer($coverage));
        foreach ([['current_units' => 1], ['partial_documents' => 1], ['fresh' => false], ['complete' => false]] as $invalid) {
            self::assertFalse(AssistantDocumentCoverageService::isCompleteForAnswer(array_replace($coverage,
                ['retrieval_coverage' => array_replace($coverage['retrieval_coverage'], $invalid)])));
        }
    }

    public function test_document_reference_retains_bounded_exact_unit_provenance(): void
    {
        $context = (new RagPromptContextBuilder)->build('', [new RagSearchResult('file_document', 'assistant_document', '77', 9,
            'Сводная таблица', 'Строка 14001', 1.0, ['document_id' => 77, 'unit_id' => 88, 'unit_checksum' => str_repeat('a', 64),
                'provenance' => ['sheet' => 'Смета', 'row' => 14001, 'cells' => ['A', 'B'], 'private_storage_path' => '/secret'],
                'text_offset' => 0, 'text_length' => 12], null)]);
        $reference = $context['metadata']['sources'][0];
        self::assertSame(88, $reference['unit_id'] ?? null);
        self::assertSame(str_repeat('a', 64), $reference['unit_checksum'] ?? null);
        self::assertEquals(['sheet' => 'Смета', 'row' => 14001, 'cells' => ['A', 'B']], $reference['provenance'] ?? null);
        self::assertSame(0, $reference['text_offset'] ?? null);
    }

    public function test_existing_search_tool_supports_exact_read_and_closed_continuation_arguments(): void
    {
        $tool = (new \ReflectionClass(SearchAssistantDocumentsTool::class))->newInstanceWithoutConstructor();
        $schema = $tool->getParametersSchema();
        foreach (['document_id', 'unit_id', 'text_offset', 'cursor'] as $field) {
            self::assertArrayHasKey($field, $schema['properties']);
            self::assertNotContains($field, $schema['required']);
        }
        self::assertFalse($schema['additionalProperties']);
    }

    private function completeCoverage(): array
    {
        return ['document_coverage' => ['total' => 1, 'ready' => 1, 'pending' => 0, 'ocr_required' => 0,
            'ocr_processing' => 0, 'failed' => 0, 'unsupported' => 0, 'empty' => 0],
            'archive_scan' => ['expected_file_count' => 1, 'scanned_file_count' => 1, 'processing' => false]];
    }

    public function test_continuation_is_bound_to_actor_organization_and_project_and_rejects_tampering(): void
    {
        config()->set('app.key', str_repeat('k', 32));
        $reader = new AssistantDocumentUnitReader((new \ReflectionClass(AssistantDataAccessPolicy::class))->newInstanceWithoutConstructor(), new RagPromptContextBuilder);
        $scope = ['actor_id' => 1, 'organization_id' => 2, 'project_id' => 3];
        $position = ['document_id' => 4, 'unit_id' => 5, 'offset' => 8000, 'unit_checksum' => str_repeat('a', 64),
            'checksum' => str_repeat('b', 64), 'version' => '2026-10-11T12:00:00+00:00', 'expires_at' => now()->addMinutes(5)->getTimestamp()];
        $cursor = $reader->encodeCursor($position, $scope);
        self::assertSame($position, $reader->decodeCursor($cursor, $scope));
        foreach ([$scope + ['extra' => 1], array_replace($scope, ['actor_id' => 9]), array_replace($scope, ['organization_id' => 9]),
            array_replace($scope, ['project_id' => 9])] as $foreign) {
            try { $reader->decodeCursor($cursor, $foreign); self::fail('Foreign scope must reject the cursor.'); }
            catch (\RuntimeException) { self::assertTrue(true); }
        }
        $this->expectException(\RuntimeException::class);
        $reader->decodeCursor('x'.$cursor, $scope);
    }

    public function test_expired_document_cursor_is_rejected(): void
    {
        config()->set('app.key', str_repeat('k', 32));
        $reader = new AssistantDocumentUnitReader((new \ReflectionClass(AssistantDataAccessPolicy::class))->newInstanceWithoutConstructor(), new RagPromptContextBuilder);
        $cursor = $reader->encodeCursor(['document_id' => 4, 'unit_id' => 5, 'offset' => 0, 'unit_checksum' => str_repeat('a', 64),
            'checksum' => str_repeat('b', 64), 'version' => '2026-10-11T12:00:00+00:00', 'expires_at' => now()->subSecond()->getTimestamp()], []);
        $this->expectException(\RuntimeException::class);
        $reader->decodeCursor($cursor, []);
    }

    public function test_rag_document_proof_survives_source_reference_collection_and_public_projection(): void
    {
        $source = ['entity_type' => 'assistant_document', 'entity_id' => '4', 'source_type' => 'file_document',
            'document_id' => 4, 'unit_id' => 5, 'unit_checksum' => str_repeat('a', 64), 'source_field_identity' => str_repeat('b', 64),
            'document_checksum' => str_repeat('c', 64), 'rag_source_checksum' => str_repeat('d', 64),
            'provenance' => ['page' => 7], 'text_offset' => 8000, 'text_length' => 1000, 'private_storage_path' => '/secret'];
        $reflection = new \ReflectionClass(\App\BusinessModules\Features\AIAssistant\Services\AIAssistantService::class);
        $refs = $reflection->getMethod('collectSourceRefs')->invoke($reflection->newInstanceWithoutConstructor(), ['sources' => [$source]], []);
        self::assertSame(5, $refs[0]['unit_id'] ?? null);
        self::assertSame(str_repeat('a', 64), $refs[0]['unit_checksum'] ?? null);
        self::assertSame(str_repeat('b', 64), $refs[0]['source_field_identity'] ?? null);
        self::assertSame(str_repeat('c', 64), $refs[0]['document_checksum'] ?? null);
        self::assertSame(str_repeat('d', 64), $refs[0]['rag_source_checksum'] ?? null);
        self::assertSame(['page' => 7], $refs[0]['provenance'] ?? null);
        self::assertArrayNotHasKey('private_storage_path', $refs[0]);
        $public = \App\BusinessModules\Features\AIAssistant\Services\AssistantToolResultProjection::forProvider('search_assistant_documents',
            ['status' => 'success', 'source_refs' => $refs]);
        self::assertSame(['page' => 7], $public['source_refs'][0]['provenance']);
        self::assertArrayNotHasKey('unit_checksum', $public['source_refs'][0]);
        self::assertArrayNotHasKey('source_field_identity', $public['source_refs'][0]);
        self::assertArrayNotHasKey('document_checksum', $public['source_refs'][0]);
        self::assertArrayNotHasKey('rag_source_checksum', $public['source_refs'][0]);
    }

    public function test_generic_rag_metadata_carries_exact_source_identity_without_losing_permissions(): void
    {
        $reflection = new \ReflectionClass(\App\BusinessModules\Features\AIAssistant\Services\Rag\RagRetriever::class);
        $row = (object) ['source_type' => 'project', 'rag_source_id' => 9, 'rag_source_checksum' => str_repeat('a', 64),
            'rag_source_version' => '2026-10-11T10:00:00+00:00', 'chunk_metadata' => ['required_permissions' => ['projects.view'],
                'assistant_public_schema_revision' => 'schema-v1', 'checksum' => 'untrusted-collector-value']];
        $metadata = $reflection->getMethod('referenceMetadata')->invoke($reflection->newInstanceWithoutConstructor(), $row);
        self::assertSame(9, $metadata['source_id']);
        self::assertSame(str_repeat('a', 64), $metadata['checksum']);
        self::assertSame('2026-10-11T10:00:00+00:00', $metadata['source_version']);
        self::assertSame(['projects.view'], $metadata['required_permissions']);
        self::assertSame('schema-v1', $metadata['assistant_public_schema_revision']);
        $metadata += ['checked_fields' => ['status'], 'source_field_identity' => str_repeat('c', 64), 'required_domains' => ['projects']];
        $context = (new RagPromptContextBuilder)->build('', [new RagSearchResult('project', 'project', '7', null, 'Проект', 'Описание', 1.0, $metadata, null)]);
        foreach (['required_permissions', 'required_domains', 'checked_fields', 'source_field_identity'] as $field) {
            self::assertSame($metadata[$field], $context['metadata']['sources'][0][$field]);
        }
        $row->source_type = 'file_document';
        $row->chunk_metadata = ['checksum' => str_repeat('b', 64), 'unit_id' => 22];
        $document = $reflection->getMethod('referenceMetadata')->invoke($reflection->newInstanceWithoutConstructor(), $row);
        self::assertSame(str_repeat('b', 64), $document['document_checksum']);
        self::assertSame(str_repeat('a', 64), $document['rag_source_checksum']);
        self::assertSame(str_repeat('a', 64), $document['checksum']);
    }

    public function test_document_proof_is_removed_from_public_nested_context_without_changing_local_evidence(): void
    {
        $source = ['entity_type' => 'assistant_document', 'entity_id' => '4', 'unit_id' => 5,
            'excerpt' => 'Текст строки', 'provenance' => ['page' => 7, 'row' => 17], 'unit_checksum' => str_repeat('a', 64),
            'document_checksum' => str_repeat('b', 64), 'rag_source_checksum' => str_repeat('c', 64), 'checksum' => str_repeat('c', 64),
            'source_field_identity' => str_repeat('d', 64), 'checked_fields' => ['checksum']];
        $original = ['status' => 'success', 'source_refs' => [$source], 'rag_context' => ['sources' => [$source]],
            'document_context' => json_encode(['kind' => 'untrusted_search_results', 'sources' => [$source]], JSON_THROW_ON_ERROR),
            'next_cursor' => 'opaque-signed-cursor'];
        $public = \App\BusinessModules\Features\AIAssistant\Services\AssistantToolResultProjection::forProvider('search_assistant_documents', $original);
        $json = json_decode($public['document_context'], true, flags: JSON_THROW_ON_ERROR);
        foreach ([$public['source_refs'][0], $public['rag_context']['sources'][0], $json['sources'][0]] as $view) {
            foreach (['unit_checksum', 'document_checksum', 'rag_source_checksum', 'checksum', 'source_field_identity', 'checked_fields'] as $proof) {
                self::assertArrayNotHasKey($proof, $view);
            }
            self::assertSame(['page' => 7, 'row' => 17], $view['provenance']);
            self::assertSame('Текст строки', $view['excerpt']);
        }
        self::assertSame('opaque-signed-cursor', $public['next_cursor']);
        self::assertSame($source, $original['source_refs'][0]);
        self::assertSame($source, $original['rag_context']['sources'][0]);
        self::assertSame($source, json_decode($original['document_context'], true, flags: JSON_THROW_ON_ERROR)['sources'][0]);
    }

    public function test_exact_unit_substring_casts_offset_and_length_even_when_pdo_binds_strings(): void
    {
        $connection = new class(null, 'offline', '', ['driver' => 'pgsql']) extends \Illuminate\Database\PostgresConnection {
            public string $substringSql = '';
            public array $substringBindings = [];

            public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
            {
                if (str_contains($query, 'SUBSTRING(')) {
                    $this->substringSql = $query;
                    $this->substringBindings = array_map('strval', $bindings);
                    return [(object) ['id' => 22, 'document_id' => 11, 'unit_index' => 0, 'unit_type' => 'text',
                        'checksum' => hash('sha256', 'Строка'), 'provenance' => '{"kind":"text"}',
                        'total_characters' => 6, 'current_checksum' => hash('sha256', 'Строка'), 'excerpt' => 'Строка']];
                }
                if (str_contains($query, '"unit_index" >')) { return []; }
                return [(object) ['id' => 22, 'unit_index' => 0, 'checksum' => hash('sha256', 'Строка')]];
            }
        };
        $previous = \Illuminate\Database\Eloquent\Model::getConnectionResolver();
        $resolver = new \Illuminate\Database\ConnectionResolver(['offline' => $connection]);
        $resolver->setDefaultConnection('offline');
        \Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);
        try {
            $reflection = new \ReflectionClass(AssistantDocumentUnitReader::class);
            $query = \App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit::query()->where('document_id', 11);
            $unit = $reflection->getMethod('readUnitFragment')->invoke($reflection->newInstanceWithoutConstructor(), $query, 22, 0, 340);
            self::assertSame('Строка', $unit->getAttribute('excerpt'));
            self::assertStringContainsString('SUBSTRING(text, CAST(? AS integer), CAST(? AS integer))', $connection->substringSql);
            self::assertSame(['1', '340'], array_slice($connection->substringBindings, 0, 2));
        } finally {
            if ($previous === null) { \Illuminate\Database\Eloquent\Model::unsetConnectionResolver(); }
            else { \Illuminate\Database\Eloquent\Model::setConnectionResolver($previous); }
        }
    }

    public function test_initial_rag_prompt_contains_public_sources_while_metadata_keeps_current_proof(): void
    {
        $proof = ['source_id' => 31, 'source_version' => '2026-10-11T10:00:00+00:00', 'checksum' => str_repeat('a', 64),
            'unit_checksum' => str_repeat('b', 64), 'document_checksum' => str_repeat('c', 64), 'rag_source_checksum' => str_repeat('a', 64),
            'source_field_identity' => str_repeat('d', 64), 'checked_fields' => ['checksum'], 'required_permissions' => ['projects.view'],
            'required_domains' => ['projects'], 'assistant_public_schema_revision' => 'schema-v1', 'document_id' => 4,
            'unit_id' => 5, 'provenance' => ['page' => 7, 'row' => 17]];
        $results = [];
        foreach ([5, 6] as $unitId) {
            $results[] = new RagSearchResult('file_document', 'assistant_document', '4', 9, 'Документ', 'Текст строки', 1.0,
                array_replace($proof, ['unit_id' => $unitId]), null);
        }
        $context = (new RagPromptContextBuilder)->build('', $results);
        $prompt = json_decode($context['prompt'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('untrusted_search_results', $prompt['kind']);
        self::assertCount(2, $prompt['sources']);
        self::assertArrayNotHasKey('source_context', $prompt);
        foreach ($prompt['sources'] as $index => $source) {
            foreach (['checksum', 'source_version', 'unit_checksum', 'document_checksum', 'rag_source_checksum', 'source_field_identity',
                'checked_fields', 'required_permissions', 'required_domains', 'assistant_public_schema_revision'] as $private) {
                self::assertArrayNotHasKey($private, $source);
                self::assertSame($proof[$private], $context['metadata']['sources'][$index][$private]);
            }
            foreach (['entity_type', 'entity_id', 'project_id', 'document_id', 'unit_id', 'provenance', 'excerpt', 'title'] as $public) {
                self::assertSame($context['metadata']['sources'][$index][$public], $source[$public]);
            }
        }
    }

    public function test_project_file_type_discovery_replaces_inherited_all_column_selection_before_distinct(): void
    {
        $connection = new class(null, 'offline', '', ['driver' => 'pgsql']) extends \Illuminate\Database\PostgresConnection {
            public string $sql = '';

            public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
            {
                $this->sql = $query;
                return [];
            }
        };
        $previous = \Illuminate\Database\Eloquent\Model::getConnectionResolver();
        $resolver = new \Illuminate\Database\ConnectionResolver(['offline' => $connection]);
        $resolver->setDefaultConnection('offline');
        \Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);
        try {
            $files = \App\Models\File::query()->select('files.*');
            $reflection = new \ReflectionClass(AssistantDocumentCoverageService::class);
            $reflection->getMethod('scopeFilesToProject')->invoke($reflection->newInstanceWithoutConstructor(), $files, new \App\Models\User, 7, 9);
            self::assertStringContainsString('select distinct "files"."fileable_type"', $connection->sql);
            self::assertStringNotContainsString('"files".*', $connection->sql);
            self::assertSame(['files.*'], $files->getQuery()->columns);
        } finally {
            if ($previous === null) { \Illuminate\Database\Eloquent\Model::unsetConnectionResolver(); }
            else { \Illuminate\Database\Eloquent\Model::setConnectionResolver($previous); }
        }
    }
}
