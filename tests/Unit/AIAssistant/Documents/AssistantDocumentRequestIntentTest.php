<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Documents;

use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentRequestIntent;
use PHPUnit\Framework\TestCase;

final class AssistantDocumentRequestIntentTest extends TestCase
{
    public function test_file_metadata_is_distinct_from_document_content_and_compound_questions(): void
    {
        foreach (['Покажи список файлов по Севгу: названия и разделы, без пересказа содержимого.',
            'Какие документы с готовым текстом доступны? Покажи имена и идентификаторы.'] as $query) {
            self::assertTrue(AssistantDocumentRequestIntent::metadataOnly($query), $query);
        }
        foreach (['Найди документы по Севгу. Что там есть по вентиляции?', 'Прочитай текст документа 11.',
            'Покажи список документов и перескажи условия.', 'Покажи имена файлов и что в них написано.',
            'Покажи список документов и их тексты.', 'Покажи список файлов и выдержки.',
            'Покажи названия файлов и описание.', 'Покажи список документов и резюме.'] as $query) {
            self::assertFalse(AssistantDocumentRequestIntent::metadataOnly($query), $query);
        }
    }

    public function test_ready_list_is_unfiltered_and_preserves_pagination_without_invented_file_ids(): void
    {
        $query = 'Какие документы с готовым текстом доступны? Покажи имена и идентификаторы.';
        $plan = ['request' => ['message' => $query]];
        $read = \App\BusinessModules\Features\AIAssistant\Services\AssistantRequiredProjectRead::fromPlan($plan);
        self::assertSame('search_assistant_documents', $read['name']);
        self::assertSame('ready', $read['arguments']['document_status']);
        self::assertNull($read['arguments']['project_id']);
        self::assertNull($read['arguments']['filename_query']);
        $bound = \App\BusinessModules\Features\AIAssistant\Services\AssistantRequiredProjectRead::bindArguments($plan, 'search_assistant_documents', ['metadata_offset' => 20, 'document_id' => 999]);
        self::assertSame(20, $bound['metadata_offset']);
        self::assertArrayNotHasKey('document_id', $bound);
        self::assertNull(AssistantDocumentRequestIntent::readyListArguments('Какие документы с готовым текстом по Севгу доступны?'));
    }
}
