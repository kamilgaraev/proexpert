<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagSearchResult;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantFinanceTenderSourceSchema as Schema;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagPromptContextBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class AssistantFinanceTenderSourceSchemaTest extends TestCase
{
    public function test_legacy_financial_body_and_history_are_denied_until_explicit_curated_proof(): void
    {
        $revision = Schema::revision('epm_data_mart_snapshot');
        self::assertIsString($revision);
        $source = ['entity_type' => 'epm_data_mart_snapshot'];
        self::assertFalse(Schema::allowsSource($source));
        self::assertFalse(Schema::allowsSource($source + ['metadata' => [Schema::FIELD => str_repeat('0', 64)]]));
        self::assertTrue(Schema::allowsSource($source + ['metadata' => [Schema::FIELD => $revision]]));
        self::assertFalse(Schema::allowsReference('epm_data_mart_snapshot', ['content_scope' => 'unstructured']));
        self::assertTrue(Schema::allowsReference('epm_data_mart_snapshot', ['content_scope' => 'unstructured', Schema::FIELD => $revision]));
        self::assertFalse(Schema::allowsReference('epm_data_mart_snapshot', ['content_scope' => 'structured', 'checked_fields' => ['payload']]));
        self::assertTrue(Schema::allowsReference('epm_data_mart_snapshot', ['content_scope' => 'structured', 'checked_fields' => ['id', 'status']]));
        self::assertNull(Schema::revision('project'));
        self::assertTrue(Schema::allowsSource(['entity_type' => 'project']));
    }

    public function test_server_context_and_main_keep_schema_proof_in_persisted_references(): void
    {
        $revision = Schema::revision('epm_data_mart_snapshot');
        $result = new RagSearchResult('budgeting', 'epm_data_mart_snapshot', 7, null, 'Снимок', 'Статус готов', 0.9,
            [Schema::FIELD => $revision]);
        $sources = (new ReflectionMethod(RagPromptContextBuilder::class, 'sources'))->invoke(new RagPromptContextBuilder, [$result]);
        self::assertSame($revision, $sources[0][Schema::FIELD]);
        $service = (new ReflectionClass(AIAssistantService::class))->newInstanceWithoutConstructor();
        $references = (new ReflectionMethod($service, 'collectSourceRefs'))->invoke($service, ['sources' => $sources], []);
        self::assertSame($revision, $references[0][Schema::FIELD]);
        self::assertTrue(Schema::allowsReference('epm_data_mart_snapshot', $references[0]));
    }
}
