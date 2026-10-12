<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentMetadataReader;
use PHPUnit\Framework\TestCase;

final class AssistantDocumentMetadataReferenceTest extends TestCase
{
    public function test_only_closed_metadata_shape_can_skip_native_body_validation(): void
    {
        $reference = ['entity_type' => 'assistant_document', 'source_type' => 'file_document',
            'content_scope' => 'structured', 'checked_fields' => AssistantDocumentMetadataReader::FIELDS];
        self::assertTrue(AssistantDocumentMetadataReader::isMetadataReference($reference));
        self::assertTrue(AssistantDocumentMetadataReader::isMetadataReference(array_replace($reference, ['checked_fields' => array_reverse($reference['checked_fields'])])));
        foreach (['source_id', 'unit_id', 'unit_checksum', 'checksum', 'text_offset', 'text_length'] as $field) {
            self::assertFalse(AssistantDocumentMetadataReader::isMetadataReference($reference + [$field => 1]), $field);
        }
        foreach ([['entity_type' => 'file'], ['source_type' => 'file'], ['content_scope' => 'document_units'],
            ['checked_fields' => ['filename']], ['checked_fields' => [...$reference['checked_fields'], 'extracted_text']],
            ['checked_fields' => [...array_slice($reference['checked_fields'], 0, 5), 'filename']],
            ['checked_fields' => [...array_slice($reference['checked_fields'], 0, 5), 1]]] as $mutation) {
            self::assertFalse(AssistantDocumentMetadataReader::isMetadataReference(array_replace($reference, $mutation)));
        }
    }
}
