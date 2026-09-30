<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\Domains\DiscoverAssistantDomainCapabilitiesTool;
use App\BusinessModules\Features\AIAssistant\Actions\Domains\SearchAssistantDocumentsTool;
use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestCancelled;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestDeadlineExceeded;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Models\Message;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentExecutor;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentPlanner;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentStateStore;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantArtifactNormalizer;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantCapabilityCatalog;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantPeriodResolver;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantResponseVerifier;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantAccessContextResolver;
use App\BusinessModules\Features\AIAssistant\Services\AssistantCapabilityRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\AssistantTaskOrchestrator;
use App\BusinessModules\Features\AIAssistant\Services\AssistantToolArgumentValidator;
use App\BusinessModules\Features\AIAssistant\Services\ContextBuilder;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantFinancialClaimVerifier;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\GetEstimateAnswerTool;
use App\BusinessModules\Features\AIAssistant\Services\IntentRecognizer;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\UsageTracker;
use App\Models\Organization;
use App\Models\User;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Services\Project\UserProjectAccessService;
use App\Services\Credits\AICreditService;
use App\Services\Logging\LoggingService;
use App\Support\AI\TokenBudgetService;
use App\Support\AI\TokenCounter;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssistantToolFirstQualityTest extends TestCase
{
    private ?Container $previousApplication;
    private ?Container $previousContainer;
    private array $providerCalls = [];
    private int $toolExecutions = 0;
    private int $assistantMessages = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApplication = Facade::getFacadeApplication();
        $this->previousContainer = Container::getInstance();
        $application = new Application(dirname(__DIR__, 3));
        $application->instance('config', new Repository(['app' => ['locale' => 'ru', 'fallback_locale' => 'ru'],
            'ai-assistant-credits' => require dirname(__DIR__, 3).'/config/ai-assistant-credits.php']));
        $application->instance('translator', new Translator(new FileLoader(new Filesystem, dirname(__DIR__, 3).'/lang'), 'ru'));
        Facade::setFacadeApplication($application);
        Facade::clearResolvedInstances();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_general_question_preserves_full_query_and_history_without_eager_reads(): void
    {
        $query = str_repeat('вопрос ', 570).'конец';
        $history = [['role' => 'user', 'content' => 'Уточняем порядок приёмки материалов.'],
            ['role' => 'assistant', 'content' => 'Сначала сверим документы поставки.']];
        $service = $this->service([], [['content' => 'Могу пояснить порядок работы.']], $history);

        $response = $service->ask($query, 15, $this->actor(), 7);

        $this->assertCount(1, $this->providerCalls);
        $this->assertSame($query, end($this->providerCalls[0]['messages'])['content']);
        $this->assertContains($history[0], $this->providerCalls[0]['messages']);
        $this->assertContains($history[1], $this->providerCalls[0]['messages']);
        $this->assertSame(0, $this->toolExecutions);
        $this->assertSame([], $response['message']['metadata']['source_refs']);
    }

    public function test_document_search_runs_only_after_luna_choice_and_preserves_sources(): void
    {
        $source = ['entity_type' => 'knowledge_article', 'entity_id' => '11', 'content_scope' => 'unstructured',
            'title' => 'Правила приёмки', 'excerpt' => 'До приёмки проверьте комплектность.'];
        $result = ['status' => 'success', 'document_context' => 'До приёмки проверьте комплектность.',
            'rag_context' => ['used' => true, 'sources' => [$source]], 'source_refs' => [$source]];
        $service = $this->service(['search_assistant_documents' => $result], [
            ['content' => '', 'tool_calls' => [$this->toolCall('search_assistant_documents')]],
            ['content' => 'До приёмки проверьте комплектность [1].'],
        ]);

        $response = $service->ask('Перескажи правила приёмки из статьи.', 15, $this->actor(), 7);

        $this->assertCount(2, $this->providerCalls);
        $this->assertSame(1, $this->toolExecutions);
        $this->assertStringNotContainsString($source['excerpt'], json_encode($this->providerCalls[0]['messages'], JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString($source['excerpt'], json_encode($this->providerCalls[1]['messages'], JSON_UNESCAPED_UNICODE));
        $this->assertSame('До приёмки проверьте комплектность [1].', $response['message']['content']);
        $this->assertTrue($response['message']['metadata']['rag_context']['used']);
        $this->assertSame('11', $response['message']['metadata']['source_refs'][0]['entity_id']);
    }

    public function test_financial_answer_uses_computed_value_in_two_calls_and_skips_document_search(): void
    {
        $source = ['entity_type' => 'estimate', 'entity_id' => 99, 'organization_id' => 15];
        $computed = 'Итого: 1 234,56 ₽';
        $result = ['status' => 'resolved', 'server_formatted_answer' => $computed, 'needs_clarification' => false,
            'financial_evidence' => ['source_refs' => [$source], 'fetched_at' => now()->toISOString(), 'version' => 'v1', 'validation_status' => 'verified'],
            'source_refs' => [$source], 'selection' => ['estimate_id' => 99]];
        $service = $this->service(['get_estimate_answer' => $result, 'search_assistant_documents' => ['status' => 'success']], [
            ['content' => '', 'tool_calls' => [$this->toolCall('get_estimate_answer')]],
            ['content' => 'Итого: 999,00 ₽'],
        ]);

        $response = $service->ask('Какова сумма сметы?', 15, $this->actor(), 7);

        $this->assertCount(2, $this->providerCalls);
        $this->assertSame(1, $this->toolExecutions);
        $this->assertSame($computed, $response['message']['content']);
        $this->assertSame('verified', $response['message']['metadata']['validation_status']);
        $this->assertFalse($response['message']['metadata']['rag_context']['used']);
        $this->assertSame(99, $response['message']['metadata']['source_refs'][0]['entity_id']);
    }

    public function test_estimate_ambiguity_preserves_server_clarification_and_its_references(): void
    {
        $clarification = 'Уточните смету: вариант А или вариант Б.';
        $source = ['entity_type' => 'estimate', 'entity_id' => 99, 'organization_id' => 15];
        $service = $this->service(['get_estimate_answer' => ['status' => 'ambiguous', 'server_formatted_answer' => $clarification,
            'needs_clarification' => true, 'source_refs' => [$source]]], [
            ['content' => '', 'tool_calls' => [$this->toolCall('get_estimate_answer')]], ['content' => 'Итого: 999,00 ₽'],
        ]);

        $response = $service->ask('Какова сумма сметы?', 15, $this->actor(), 7);

        $this->assertSame($clarification, $response['message']['content']);
        $this->assertTrue($response['message']['metadata']['needs_clarification']);
        $this->assertSame(99, $response['message']['metadata']['source_refs'][0]['entity_id']);
    }

    public function test_additional_source_read_remains_available_within_normal_call_budget(): void
    {
        $service = $this->service(['assistant_domain_discover_capabilities' => ['status' => 'success'],
            'search_assistant_documents' => ['status' => 'success', 'document_context' => 'Указаны правила проверки.',
                'rag_context' => ['sources' => []]]], [
            ['content' => '', 'tool_calls' => [$this->toolCall('assistant_domain_discover_capabilities')]],
            ['content' => '', 'tool_calls' => [$this->toolCall('search_assistant_documents')]],
            ['content' => 'Правила проверки получены.'],
        ]);

        $response = $service->ask('Найди правила проверки документов.', 15, $this->actor(), 7);

        $this->assertCount(3, $this->providerCalls);
        $this->assertSame(2, $this->toolExecutions);
        $this->assertSame('Правила проверки получены.', $response['message']['content']);
    }

    public function test_stock_answer_preserves_all_proof_sources_without_sending_receipt_to_provider(): void
    {
        $refs = array_map(static fn (int $id): array => ['entity_type' => 'warehouse_balance', 'entity_id' => $id,
            'organization_id' => 15], range(1, 75));
        $stock = ['status' => 'success', 'stock' => [['material_id' => 11, 'material_name' => 'Бетон',
            'available_quantity' => '10.125', 'reserved_quantity' => '1.500', 'unit_short_name' => 'м³']],
            'server_formatted_answer' => 'Бетон: доступно 10.125 м³, в резерве 1.500 м³.',
            'validation_status' => 'verified', 'source_refs' => $refs,
            'stock_evidence' => ['contributions' => [['private_receipt_marker' => 'internal_checked_rows']]]];
        $service = $this->service(['get_material_stock' => $stock], [
            ['content' => '', 'tool_calls' => [$this->toolCall('get_material_stock')]], ['content' => 'Доступно 999 м³.'],
        ]);
        $service->verifiedStock = ['text' => $stock['server_formatted_answer'], 'validation_status' => 'verified',
            'source_refs' => $refs, 'replaced' => true, 'needs_clarification' => false];

        $response = $service->ask('Сколько бетона на складе?', 15, $this->actor(), 7);

        $this->assertSame($stock['server_formatted_answer'], $response['message']['content']);
        $this->assertCount(75, $response['message']['metadata']['source_refs']);
        $this->assertSame('verified', $response['message']['metadata']['validation_status']);
        $providerInput = json_encode($this->providerCalls[1]['messages'], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('internal_checked_rows', $providerInput);
        $this->assertStringNotContainsString('stock_evidence', $providerInput);
        $this->assertStringContainsString('10.125', $providerInput);
        $this->assertSame(1, $service->stockVerifications);
    }

    public function test_timed_out_read_is_unavailable_and_cannot_prove_absent_data(): void
    {
        $failure = new \Illuminate\Database\QueryException('pgsql', 'select 1', [], new \PDOException('cancelled query'));
        $failure->errorInfo = ['57014'];
        $service = $this->service(['get_material_stock' => $failure], [
            ['content' => '', 'tool_calls' => [$this->toolCall('get_material_stock')]], ['content' => 'На складе 0 материалов.'],
        ]);

        $response = $service->ask('Сколько бетона на складе?', 15, $this->actor(), 7);

        $toolMessage = array_values(array_filter($this->providerCalls[1]['messages'], static fn (array $message): bool => $message['role'] === 'tool'))[0];
        $result = json_decode($toolMessage['content'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('unavailable', $result['status']);
        $this->assertSame('read_timed_out', $result['reason']);
        $this->assertArrayNotHasKey('stock', $result);
        $this->assertSame([], $response['message']['metadata']['source_refs']);
        $this->assertStringNotContainsString('0 материалов', $response['message']['content']);
        $this->assertSame('service_error', $response['message']['metadata']['outcome']);
    }

    public function test_compound_need_stock_and_deliveries_preserve_verified_parts_without_invented_difference(): void
    {
        $estimateRef = ['entity_type' => 'estimate', 'entity_id' => 99, 'organization_id' => 15];
        $stockRef = ['entity_type' => 'warehouse_balance', 'entity_id' => 41, 'organization_id' => 15];
        $fetchedAt = now()->toISOString();
        $deliveryRef = ['entity_type' => 'purchase_order', 'entity_id' => 12, 'organization_id' => 15,
            'content_scope' => 'structured', 'checked_fields' => ['material_name', 'material_quantity', 'material_unit'], 'fetched_at' => $fetchedAt];
        $deliveries = \App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactFormatter::payload([
            ['entity_type' => 'purchase_order', 'entity_id' => 12, 'fields' => ['material_name' => 'Бетон',
                'material_quantity' => '4.000', 'material_unit' => 'м³'], 'source_ref' => $deliveryRef, 'version' => 'delivery-v1'],
        ], $fetchedAt);
        $estimateText = 'Сметная потребность: бетон 8.000 м³.';
        $stockText = 'Доступно: бетон 10.125 м³; бетонная смесь 30 мешков.';
        $service = $this->service([
            'get_estimate_answer' => ['status' => 'resolved', 'server_formatted_answer' => $estimateText,
                'needs_clarification' => false, 'validation_status' => 'verified', 'source_refs' => [$estimateRef],
                'financial_evidence' => ['source_refs' => [$estimateRef], 'fetched_at' => $fetchedAt, 'version' => 'estimate-v1', 'validation_status' => 'verified']],
            'get_material_stock' => ['status' => 'success', 'stock' => [], 'stock_evidence' => ['version' => 'stock-v1'], 'source_refs' => [$stockRef]],
            'assistant_domain_read' => ['status' => 'success', ...$deliveries],
        ], [['content' => '', 'tool_calls' => [$this->toolCall('get_estimate_answer'), $this->toolCall('get_material_stock'),
            $this->toolCall('assistant_domain_read')]], ['content' => 'Суммарно 999 м³. Дефицит 777 м³.']]);
        $service->verifiedStock = ['text' => $stockText, 'validation_status' => 'verified', 'source_refs' => [$stockRef],
            'replaced' => true, 'needs_clarification' => false];

        $response = $service->ask('Бетон: сметная потребность, остатки на складе и поставки. Какой дефицит?', 15, $this->actor(), 7);

        $content = $response['message']['content'];
        $this->assertStringContainsString($estimateText, $content);
        $this->assertStringContainsString($stockText, $content);
        $this->assertStringContainsString('4.000', $content);
        $this->assertStringContainsString('Сопоставление потребности', $content);
        $this->assertStringNotContainsString('999', $content);
        $this->assertStringNotContainsString('777', $content);
        $this->assertCount(3, $response['message']['metadata']['source_refs']);
        $this->assertSame('partial', $response['message']['metadata']['validation_status']);
        $this->assertCount(2, $this->providerCalls);
        $this->assertSame(3, $this->toolExecutions);
    }

    public function test_compact_catalog_contains_only_allowed_registered_domains(): void
    {
        $reflection = new \ReflectionClass(DiscoverAssistantDomainCapabilitiesTool::class);
        $tool = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('catalog')->setValue($tool, new AssistantDomainCatalog(AssistantDomainCatalog::defaults()));
        $catalog = $tool->compact(static fn (string $domain): bool => in_array($domain, ['projects', 'estimates'], true), static fn (): bool => true);
        $domains = array_column($catalog['domains'], 'domain');

        $this->assertContains('projects', $domains);
        $this->assertContains('estimates', $domains);
        $this->assertNotContains('finance', $domains);
        $this->assertNotContains('contracts', $domains);
        $this->assertSame('checked_on_read', $catalog['record_access']);
        foreach ($catalog['domains'] as $row) {
            $this->assertArrayNotHasKey('fields', $row);
            $this->assertArrayNotHasKey('results', $row);
        }
    }

    public function test_search_schemas_preserve_4000_character_query_and_reject_scope_injection(): void
    {
        $tool = (new \ReflectionClass(SearchAssistantDocumentsTool::class))->newInstanceWithoutConstructor();
        $schema = $tool->getParametersSchema();
        $validator = new AssistantToolArgumentValidator;
        $arguments = ['query' => str_repeat('я', 4000), 'project_id' => null, 'source_types' => null, 'limit' => null];
        $validator->validate($arguments, $schema);
        $this->assertSame(4000, $schema['properties']['query']['maxLength']);
        $estimate = (new \ReflectionClass(GetEstimateAnswerTool::class))->newInstanceWithoutConstructor();
        $this->assertSame(4000, $estimate->getParametersSchema()['properties']['query']['maxLength']);
        $this->expectException(\RuntimeException::class);
        $validator->validate($arguments + ['organization_id' => 999], $schema);
    }

    public function test_document_search_distinguishes_failure_partial_fallback_and_healthy_empty(): void
    {
        $source = ['entity_type' => 'knowledge_article', 'entity_id' => '11', 'excerpt' => 'Проверенный текст.'];
        $context = ['prompt' => 'Проверенный текст.', 'metadata' => ['query' => 'private_query_marker', 'sources' => [$source]]];
        $failure = ['status' => 'unavailable', 'semantic_available' => false, 'lexical_used' => true,
            'exception' => 'private_exception_marker', 'query' => 'private_query_marker'];
        $empty = ['prompt' => '', 'metadata' => ['query' => 'private_query_marker', 'sources' => []]];
        $unavailable = SearchAssistantDocumentsTool::responseForSearch($empty, $failure);
        $partial = SearchAssistantDocumentsTool::responseForSearch($context, array_replace($failure, ['status' => 'partial']));
        $healthyEmpty = SearchAssistantDocumentsTool::responseForSearch($empty, ['status' => 'available',
            'semantic_available' => true, 'lexical_used' => true]);

        $this->assertSame('unavailable', $unavailable['status']);
        $this->assertArrayNotHasKey('document_context', $unavailable);
        $this->assertSame([], $unavailable['source_refs']);
        $this->assertSame('partial', $partial['status']);
        $this->assertSame('Проверенный текст.', $partial['document_context']);
        $this->assertFalse($partial['search_diagnostics']['semantic_available']);
        $this->assertSame('11', $partial['source_refs'][0]['entity_id']);
        $this->assertSame('success', $healthyEmpty['status']);
        $this->assertSame([], $healthyEmpty['source_refs']);
        $this->assertArrayNotHasKey('error_code', $healthyEmpty['search_diagnostics']);
        $serialized = json_encode([$unavailable, $partial, $healthyEmpty], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private_query_marker', $serialized);
        $this->assertStringNotContainsString('private_exception_marker', $serialized);
    }

    public function test_unavailable_document_search_cannot_be_answered_as_no_matches(): void
    {
        $failure = SearchAssistantDocumentsTool::responseForSearch(['prompt' => '', 'metadata' => ['sources' => []]],
            ['status' => 'unavailable', 'semantic_available' => false, 'lexical_used' => true]);
        $service = $this->service(['search_assistant_documents' => $failure], [
            ['content' => '', 'tool_calls' => [$this->toolCall('search_assistant_documents')]], ['content' => 'Документов нет.'],
        ]);

        $response = $service->ask('Найди текст правил приёмки из документов.', 15, $this->actor(), 7);

        $this->assertSame(trans_message('ai_assistant.document_search_unavailable'), $response['message']['content']);
        $this->assertSame([], $response['message']['metadata']['source_refs']);
        $this->assertTrue($response['message']['metadata']['degraded_mode']);
        $this->assertSame('service_error', $response['message']['metadata']['outcome']);
    }

    public function test_partial_document_search_preserves_sources_and_exposes_search_limit(): void
    {
        $source = ['entity_type' => 'knowledge_article', 'entity_id' => '11', 'excerpt' => 'Проверьте комплектность.'];
        $partial = SearchAssistantDocumentsTool::responseForSearch(['prompt' => 'Проверьте комплектность.',
            'metadata' => ['sources' => [$source]]], ['status' => 'partial', 'semantic_available' => false, 'lexical_used' => true]);
        $service = $this->service(['search_assistant_documents' => $partial], [
            ['content' => '', 'tool_calls' => [$this->toolCall('search_assistant_documents')]], ['content' => 'Проверьте комплектность [1].'],
        ]);

        $response = $service->ask('Перескажи правила из статьи.', 15, $this->actor(), 7);

        $this->assertStringContainsString('Проверьте комплектность [1].', $response['message']['content']);
        $this->assertStringContainsString(trans_message('ai_assistant.document_search_partial'), $response['message']['content']);
        $this->assertSame('11', $response['message']['metadata']['source_refs'][0]['entity_id']);
        $this->assertTrue($response['message']['metadata']['rag_context']['used']);
        $this->assertTrue($response['message']['metadata']['degraded_mode']);
    }

    public function test_stock_schema_exposes_direct_text_filter_without_required_entity_lookup(): void
    {
        $tool = (new \ReflectionClass(\App\BusinessModules\Features\AIAssistant\Actions\Domains\GetMaterialStockTool::class))->newInstanceWithoutConstructor();
        $registry = new AIToolRegistry;
        $registry->registerTool($tool);
        $definition = $registry->getToolsDefinitions(['get_material_stock'])[0]['function'];
        $properties = $definition['parameters']['properties'];
        (new AssistantToolArgumentValidator)->validate(['query' => 'Сухая смесь', 'material_ids' => null,
            'project_id' => null, 'warehouse_id' => null], $definition['parameters']);

        $this->assertSame(['string', 'null'], $properties['query']['type']);
        $this->assertSame(['array', 'null'], $properties['material_ids']['type']);
        $this->assertSame(['integer', 'null'], $properties['project_id']['type']);
        $this->assertSame(['integer', 'null'], $properties['warehouse_id']['type']);
        $this->assertArrayNotHasKey('entity_type', $properties);
        $this->assertArrayNotHasKey('domain', $properties);
        $this->assertNotEmpty($properties['query']['description']);
        $this->assertNotEmpty($properties['material_ids']['description']);
        $this->assertStringContainsString('Все совпавшие материалы', $definition['description']);
        $this->assertStringContainsString('по материалу и единице', $definition['description']);
    }

    public function test_verified_empty_stock_and_catalog_matches_form_a_human_answer_without_selecting_every_match(): void
    {
        $fetchedAt = now()->toISOString();
        $rows = [];
        foreach ([61, 62] as $id) {
            $fields = ['id' => $id, 'name' => 'Щебень фракции '.($id === 61 ? '5-20' : '20-40'), 'code' => 'М-'.$id,
                'organization_id' => 15, 'measurement_unit_id' => 23, 'created_at' => '2026-09-01', 'unit_price' => '999.99', 'date' => null];
            $ref = ['entity_type' => 'material', 'entity_id' => $id, 'organization_id' => 15, 'content_scope' => 'structured',
                'checked_fields' => array_keys($fields), 'fetched_at' => $fetchedAt];
            $rows[] = ['entity_type' => 'material', 'entity_id' => $id, 'fields' => $fields, 'source_ref' => $ref, 'version' => 'catalog-v1'];
        }
        $catalog = \App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactFormatter::payload($rows, $fetchedAt);
        $emptyText = trans_message('ai_assistant.material_stock_empty');
        $service = $this->service([
            'assistant_domain_search' => ['status' => 'success', 'source_refs' => array_column($rows, 'source_ref'), ...$catalog],
            'get_material_stock' => ['status' => 'empty', 'stock' => [], 'stock_evidence' => ['version' => 'stock-v1'], 'source_refs' => []],
        ], [['content' => '', 'tool_calls' => [$this->toolCall('assistant_domain_search'), $this->toolCall('get_material_stock')]],
            ['content' => 'Всего 777 м³ на складе.']]);
        $service->verifiedStock = ['text' => $emptyText, 'validation_status' => 'verified', 'source_refs' => [],
            'replaced' => true, 'needs_clarification' => false];

        $response = $service->ask('Что у нас по щебню?', 15, $this->actor(), 7);

        $content = $response['message']['content'];
        $this->assertStringStartsWith($emptyText, $content);
        $this->assertStringContainsString('Щебень фракции', $content);
        foreach (['Идентификатор', 'измерение', '2026', 'не указано', '999.99', '777', '№61', '№62'] as $technicalOrUnsupported) {
            $this->assertStringNotContainsString($technicalOrUnsupported, $content);
        }
        $this->assertLessThan(1000, mb_strlen($content));
        $this->assertSame(array_column($rows, 'source_ref'), $response['message']['metadata']['source_refs']);
        $pending = (new \ReflectionProperty(AIAssistantService::class, 'pendingSummary'))->getValue($service);
        $this->assertSame([], $pending['selected_entities']);
        $this->assertSame($response['message']['metadata']['source_refs'], $pending['source_refs']);
    }

    public function test_verified_empty_stock_is_a_useful_scoped_answer_without_generic_missing_proof_warning(): void
    {
        $emptyText = trans_message('ai_assistant.material_stock_empty');
        $service = $this->service(['get_material_stock' => ['status' => 'empty', 'stock' => [],
            'stock_evidence' => ['version' => 'checked-empty-scope'], 'source_refs' => []]],
            [['content' => '', 'tool_calls' => [$this->toolCall('get_material_stock')]], ['content' => 'Всего материалов в организации 0.']]);
        $service->verifiedStock = ['text' => $emptyText, 'validation_status' => 'verified', 'source_refs' => [],
            'replaced' => true, 'needs_clarification' => false];

        $response = $service->ask('Сколько арматуры на складе?', 15, $this->actor(), 7);

        $this->assertSame($emptyText, $response['message']['content']);
        $this->assertSame('verified', $response['message']['metadata']['validation_status']);
        $this->assertFalse($response['message']['metadata']['needs_clarification']);
        $this->assertSame([], $response['message']['metadata']['source_refs']);
        $this->assertSame([], $response['message']['metadata']['missing_data']);
        $this->assertTrue((new \ReflectionMethod(AIAssistantService::class, 'isUsefulAnswer'))->invoke($service, $response));
    }

    public function test_resolved_estimate_without_current_access_does_not_become_selected_while_answer_sources_remain_for_fresh_verification(): void
    {
        $references = [['entity_type' => 'estimate', 'entity_id' => 99, 'organization_id' => 15],
            ['entity_type' => 'estimate_item', 'entity_id' => 100, 'organization_id' => 15]];
        $answer = ['status' => 'resolved', 'selection' => ['estimate_id' => 99], 'needs_clarification' => false,
            'server_formatted_answer' => 'Сумма сметы: 123.45 руб.', 'validation_status' => 'verified', 'source_refs' => $references,
            'financial_evidence' => ['source_refs' => $references, 'fetched_at' => now()->toISOString(), 'version' => 'estimate-v1', 'validation_status' => 'verified']];
        $service = $this->service(['get_estimate_answer' => $answer], [['content' => '', 'tool_calls' => [$this->toolCall('get_estimate_answer')]],
            ['content' => 'Сумма 0 руб.']]);

        $response = $service->ask('Какова сумма выбранной сметы?', 15, $this->actor(), 7);

        $pending = (new \ReflectionProperty(AIAssistantService::class, 'pendingSummary'))->getValue($service);
        $this->assertSame([], $pending['selected_entities']);
        $this->assertSame($references, $pending['source_refs']);
        $this->assertSame($references, $response['message']['metadata']['source_refs']);
        $this->assertStringContainsString('123.45', $response['message']['content']);
    }

    #[DataProvider('stockVerificationStates')]
    public function test_actual_payload_drops_only_preliminary_domain_warning_after_authoritative_stock_verification(string $status, ?string $verification, bool $domainResolved, ?string $additionalMissing = 'Для сравнения не выполнена дополнительная проверка.'): void
    {
        $orchestrator = new AssistantTaskOrchestrator(new AssistantCapabilityRegistry, $this->createMock(AssistantAccessContextResolver::class));
        $limits = [['code' => 'actions_locked', 'message' => 'Изменяющие действия отключены до отдельного подтверждения.']];
        $preliminary = $orchestrator->buildPayload(['capability' => [], 'request' => ['context' => []]], 'Ответ', [])['missing_data'];
        $references = $status === 'success' ? [['entity_type' => 'warehouse_balance', 'entity_id' => 41, 'organization_id' => 15]] : [];
        $stock = ['status' => $status, 'stock' => [], 'stock_evidence' => ['version' => 'current-stock-receipt'], 'source_refs' => $references];
        $service = $this->service(['get_material_stock' => $stock],
            [['content' => '', 'tool_calls' => [$this->toolCall('get_material_stock')]], ['content' => 'Данные получены.']], [],
            static function (array $plan, string $answer, array $options) use ($orchestrator, $additionalMissing, $limits): array {
                $plan['access_limits'] = $limits;
                if ($additionalMissing !== null) {
                    $options['missing_data'][] = $additionalMissing;
                }

                return $orchestrator->buildPayload($plan, $answer, $options);
            });
        $service->verifiedStock = $verification === null ? null : ['text' => trans_message('ai_assistant.material_stock_empty'),
            'validation_status' => $verification, 'source_refs' => $references, 'replaced' => true, 'needs_clarification' => false];

        $response = $service->ask('Проверь текущие складские остатки.', 15, $this->actor(), 7);

        $metadata = $response['message']['metadata'];
        $otherMissing = $additionalMissing === null ? [] : [$additionalMissing];
        $this->assertSame($domainResolved ? $otherMissing : [...$preliminary, ...$otherMissing], $metadata['missing_data']);
        $this->assertSame($limits, $metadata['access_limits']);
        $this->assertSame($references, $metadata['source_refs']);
        $this->assertSame('assistant_tool', $metadata['evidence'][0]['source']);
        if ($domainResolved) {
            $this->assertSame('verified', $metadata['validation_status']);
            $this->assertFalse($metadata['needs_clarification']);
            $this->assertSame(1, $service->stockVerifications);
        }
    }

    public static function stockVerificationStates(): array
    {
        return [['empty', 'verified', true, null], ['empty', 'verified', true], ['success', 'verified', true], ['empty', 'partial', false], ['unavailable', null, false]];
    }

    #[DataProvider('sectionRoutes')]
    public function test_section_navigation_uses_registered_route_and_fresh_permissions_without_provider_or_entity_reads(string $query, string $permission, string $route): void
    {
        $service = $this->service([], [], navigationPermissions: [[$permission], [$permission]]);

        $response = $service->ask($query, 15, $this->actor(), 7, ['context' => [
            'source_module' => 'ai-assistant', 'source_route' => '/dashboard',
            'ui_state' => ['pathname' => '/dashboard', 'assistant_path' => '/assistant'],
        ]]);

        $metadata = $response['message']['metadata'];
        $this->assertSame(['route' => $route], $metadata['navigation_target']);
        $this->assertSame($route, $metadata['next_actions'][0]['target']['route']);
        $this->assertSame('verified', $metadata['validation_status']);
        $this->assertSame([], $metadata['missing_data']);
        $this->assertSame([], $metadata['access_limits']);
        $this->assertSame([], $metadata['source_refs']);
        $this->assertSame(0, $response['tokens_used']);
        $this->assertSame([], $this->providerCalls);
        $this->assertSame(0, $this->toolExecutions);
    }

    public static function sectionRoutes(): array
    {
        return [['Где сметы?', 'budget-estimates.view', '/estimates'],
            ['Где раздел проектов?', 'projects.view', '/projects'],
            ['Открой склад', 'warehouse.view', '/warehouse'],
            ['Как найти договоры?', 'contracts.view', '/contracts']];
    }

    public function test_section_navigation_rechecks_revoked_permission_and_does_not_publish_old_route(): void
    {
        $service = $this->service([], [], navigationPermissions: [['budget-estimates.view'], []]);

        $response = $service->ask('Где сметы?', 15, $this->actor(), 7);

        $this->assertNull($response['message']['metadata']['navigation_target']);
        $this->assertSame([], $response['message']['metadata']['next_actions']);
        $this->assertSame('access_denied', $response['message']['metadata']['outcome']);
        $this->assertStringContainsString('недоступен', $response['message']['content']);
        $this->assertSame([], $this->providerCalls);
    }

    public function test_section_navigation_checks_current_membership_before_domain_navigation_publication(): void
    {
        $actor = $this->actor();
        $actor->is_active = false;
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->expects($this->once())->method('forCurrentChecks')->with(true)->willReturnSelf();
        $policy = new AssistantDataAccessPolicy($authorization, $this->createMock(UserProjectAccessService::class));
        $service = $this->service([], [], navigationPermissions: [['budget-estimates.view']], navigationPolicy: $policy,
            afterNavigationAccess: static function () use ($actor): void { $actor->is_active = false; });

        $response = $service->ask('Где сметы?', 15, $actor, 7);

        $this->assertNull($response['message']['metadata']['navigation_target']);
        $this->assertSame([], $response['message']['metadata']['next_actions']);
        $this->assertSame('access_denied', $response['message']['metadata']['outcome']);
    }

    #[DataProvider('navigationBoundaryFailures')]
    public function test_section_navigation_does_not_publish_after_cancellation_or_deadline(string $failure): void
    {
        $actor = $this->createPartialMock(User::class, ['refresh', 'belongsToOrganization']);
        $actor->forceFill(['id' => 7, 'current_organization_id' => 15, 'is_active' => true]);
        $actor->method('refresh')->willReturnSelf();
        $actor->method('belongsToOrganization')->willReturn(true);
        $request = (new AssistantRequest)->setDateFormat('Y-m-d H:i:s');
        $request->forceFill(['user_id' => 7, 'organization_id' => 15, 'status' => 'running', 'conversation_id' => null,
            'cancel_requested_at' => null, 'lease_expires_at' => now()->addMinute()]);
        $permissions = $this->createMock(AIPermissionChecker::class);
        $permissions->method('canUseAssistant')->willReturn(true);
        $lifecycle = new AssistantRequestLifecycle((new \ReflectionClass(AICreditService::class))->newInstanceWithoutConstructor(),
            $permissions, $this->createMock(ConversationManager::class),
            (new \ReflectionClass(AssistantDataAccessPolicy::class))->newInstanceWithoutConstructor());
        $execution = new AssistantRequestExecutionContext($lifecycle, $request, $actor, 30_000);
        app()->instance(AssistantRequestExecutionContext::class, $execution);
        $service = $this->service([], [], navigationPermissions: [['budget-estimates.view']],
            afterNavigationAccess: static function () use ($execution, $failure, $request): void {
                if ($failure === 'cancelled') {
                    $request->cancel_requested_at = now();
                } else {
                    (new \ReflectionProperty($execution, 'deadlineNanoseconds'))->setValue($execution, hrtime(true) - 1);
                }
            });

        try {
            $service->ask('Где сметы?', 15, $actor, 7);
            $this->fail('A failed boundary must prevent publication');
        } catch (AssistantRequestCancelled|AssistantRequestDeadlineExceeded $exception) {
            $this->assertSame($failure === 'cancelled' ? AssistantRequestCancelled::class : AssistantRequestDeadlineExceeded::class, $exception::class);
        }
        $this->assertSame(0, $this->assistantMessages);
        $this->assertSame([], $this->providerCalls);
    }

    public static function navigationBoundaryFailures(): array
    {
        return [['cancelled'], ['deadline']];
    }

    #[DataProvider('sectionDataScopes')]
    public function test_section_fast_path_does_not_discard_entity_filters_or_stale_selection(string $query, array $context, array $conversationContext = []): void
    {
        $service = $this->service([], [['content' => 'Нужен проверенный источник.']], navigationPermissions: [['budget-estimates.view']]);
        $service->conversation->context = $conversationContext;

        $response = $service->ask($query, 15, $this->actor(), 7, ['context' => $context]);

        $this->assertCount(1, $this->providerCalls);
        $this->assertNotSame('navigation', $response['message']['metadata']['response_kind'] ?? null);
    }

    public static function sectionDataScopes(): array
    {
        return [['Где смета «Дом у реки»?', []], ['Где смета №42?', []],
            ['Где сметы?', ['filters' => ['status' => 'draft']]],
            ['Где сметы?', ['entity_refs' => [['type' => 'estimate', 'id' => 99]]]],
            ['Где сметы?', ['selected_estimate_id' => 99]],
            ['Где сметы?', [], ['selected_estimate' => ['estimate_id' => 99]]]];
    }

    private function actor(): User
    {
        $actor = new User;
        $actor->forceFill(['id' => 7, 'current_organization_id' => 15, 'is_active' => true]);
        return $actor;
    }

    private function toolCall(string $name): array
    {
        return ['id' => 'call_read', 'type' => 'function', 'function' => ['name' => $name, 'arguments' => '{}']];
    }

    private function service(array $toolResults, array $responses, array $history = [], ?callable $payloadBuilder = null, ?array $navigationPermissions = null,
        ?AssistantDataAccessPolicy $navigationPolicy = null, ?callable $afterNavigationAccess = null): ToolFirstStubService
    {
        $registry = new AIToolRegistry;
        foreach ($toolResults as $name => $result) {
            $tool = $this->createMock(AIToolInterface::class);
            $tool->method('getName')->willReturn($name);
            $tool->method('getDescription')->willReturn('Readonly tool');
            $tool->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => [], 'additionalProperties' => false]);
            $tool->method('execute')->willReturnCallback(function () use ($result): array {
                $this->assertNotEmpty($this->providerCalls);
                $this->toolExecutions++;
                if ($result instanceof \Throwable) {
                    throw $result;
                }
                return $result;
            });
            $registry->registerTool($tool);
        }
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->method('getModel')->willReturn('openai/gpt-6-luna');
        $provider->expects($this->exactly(count($responses)))->method('chat')->willReturnCallback(function (array $messages, array $options) use (&$responses): array {
            $this->providerCalls[] = ['messages' => $messages, 'options' => $options];
            return array_shift($responses) + ['input_tokens' => 10, 'output_tokens' => 10, 'tokens_used' => 20, 'model' => 'openai/gpt-6-luna'];
        });
        $conversation = $this->getMockBuilder(Conversation::class)->onlyMethods(['save'])->getMock();
        $conversation->forceFill(['id' => 7, 'organization_id' => 15, 'user_id' => 7, 'context' => []]);
        $conversation->method('save')->willReturn(true);
        $manager = $this->createMock(ConversationManager::class);
        $manager->method('getMessagesForContextWithBudget')->willReturn($history);
        $manager->method('getSummary')->willReturn(null);
        $manager->method('updateContext')->willReturn($conversation);
        $manager->method('addMessage')->willReturnCallback(function (Conversation $conversation, string $role, string $content, int $tokens, string $model, array $metadata): Message {
            if ($role === 'assistant') { $this->assistantMessages++; }
            return new Message(['id' => 11, 'role' => $role, 'content' => $content, 'metadata' => $metadata, 'created_at' => now()]);
        });
        $context = $this->createMock(ContextBuilder::class);
        $context->expects($this->never())->method('buildContext');
        $context->method('buildSystemPrompt')->willReturn('Помощник МОСТ.');
        $permissions = $this->createMock(AIPermissionChecker::class);
        $permissions->method('canUseAssistant')->willReturn(true);
        $permissions->method('canExecuteTool')->willReturn(true);
        $permissions->method('canExposeTool')->willReturn(true);
        $permissions->method('isMutationTool')->willReturn(false);
        $usage = $this->createMock(UsageTracker::class);
        $usage->method('canMakeRequest')->willReturn(true);
        $usage->method('getUsageStats')->willReturn([]);
        $accessResolver = $this->createMock(AssistantAccessContextResolver::class);
        if ($navigationPermissions === null) {
            $orchestrator = $this->createMock(AssistantTaskOrchestrator::class);
            $orchestrator->method('plan')->willReturnCallback(static fn (string $query): array => ['request' => ['message' => $query, 'context' => [], 'allow_actions' => false],
                'task_type' => 'summary', 'capability' => [], 'access_context_public' => []]);
            $orchestrator->method('buildPayload')->willReturnCallback($payloadBuilder ?? static fn (array $plan, string $answer, array $options): array => $options + ['answer' => $answer]);
        } else {
            $accessResolver = $this->getMockBuilder(AssistantAccessContextResolver::class)->disableOriginalConstructor()->onlyMethods(['resolve'])->getMock();
            $accessResolutions = 0;
            $accessResolver->method('resolve')->willReturnCallback(static function () use (&$navigationPermissions, &$accessResolutions, $afterNavigationAccess): array {
                if (++$accessResolutions > 1) { $afterNavigationAccess?->__invoke(); }
                $permissions = count($navigationPermissions) > 1 ? array_shift($navigationPermissions) : $navigationPermissions[0];
                return ['can_use_assistant' => true, 'permissions_flat' => $permissions, 'permissions_structured' => [], 'available_modules' => [], 'is_read_only' => true];
            });
            $orchestrator = new AssistantTaskOrchestrator(new AssistantCapabilityRegistry, $accessResolver);
        }
        $service = new ToolFirstStubService($provider, $manager, $context, $this->createMock(IntentRecognizer::class), $usage,
            $this->createMock(LoggingService::class), $registry, $permissions, $accessResolver, $orchestrator,
            new AssistantAgentStateStore, new AssistantAgentPlanner(new AssistantCapabilityCatalog, new AssistantPeriodResolver),
            new AssistantAgentExecutor($registry, $permissions, new AssistantArtifactNormalizer), new AssistantResponseVerifier,
            tokenBudget: new TokenBudgetService(new TokenCounter(new class {
                public function encode(string $text): array { return array_fill(0, (int) ceil(mb_strlen($text) / 4), 1); }
            })), financialClaims: new AssistantFinancialClaimVerifier, dataAccess: $navigationPolicy);
        $service->conversation = $conversation;
        return $service;
    }
}

final class ToolFirstStubService extends AIAssistantService
{
    public Conversation $conversation;

    public ?array $verifiedStock = null;

    public int $stockVerifications = 0;

    protected function verifyMaterialStock(array $result, User $actor, int $organizationId): ?array
    {
        $this->stockVerifications++;
        return $this->verifiedStock;
    }

    protected function getOrCreateConversation(?int $conversationId, int $organizationId, User $user): Conversation
    {
        return $this->conversation;
    }

    protected function resolveOrganization(int $organizationId): Organization
    {
        $organization = new Organization;
        $organization->id = $organizationId;
        return $organization;
    }

    protected function handleAgentFlow(string $query, int $organizationId, User $user, Conversation $conversation, array $taskPlan): ?array
    {
        return null;
    }

    protected function buildRagContext(string $query, int $organizationId, User $user, array $taskPlan, array $requestPayload): array
    {
        throw new \LogicException('Eager RAG search is forbidden');
    }
}
