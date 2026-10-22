<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagSearchResult;
use App\BusinessModules\Features\AIAssistant\Actions\Domains\SearchAssistantDocumentsTool;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagPromptContextBuilder;
use App\BusinessModules\Features\AIAssistant\Services\AssistantToolResultProjection;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Unit\AIAssistant\UsesAssistantUnitTranslations;

class RagPromptContextBuilderTest extends TestCase
{
    use UsesAssistantUnitTranslations { setUp as private setUpTranslations; }

    protected function setUp(): void
    {
        $this->setUpTranslations();

        config()->set('ai-assistant.rag.max_chunks', 8);
    }

    public function test_builds_prompt_and_metadata_from_results(): void
    {
        $builder = new RagPromptContextBuilder;
        $result = new RagSearchResult(
            sourceType: 'schedule',
            entityType: 'schedule',
            entityId: '56',
            projectId: 10,
            title: 'Schedule source',
            excerpt: 'Concrete evidence from schedule.',
            similarity: 0.84321,
            metadata: ['status' => 'active'],
            updatedAt: new DateTimeImmutable('2026-05-23T10:00:00+03:00')
        );

        $context = $builder->build('what is blocked', [$result]);

        $data = json_decode($context['prompt'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('untrusted_search_results', $data['kind']);
        $this->assertSame('Schedule source', $data['sources'][0]['title']);
        $this->assertSame('Concrete evidence from schedule.', $data['sources'][0]['excerpt']);
        $this->assertArrayNotHasKey('instructions', $data);
        $this->assertTrue($context['metadata']['enabled']);
        $this->assertTrue($context['metadata']['used']);
        $this->assertSame('what is blocked', $context['metadata']['query']);
        $this->assertSame(8, $context['metadata']['limits']['requested']);
        $this->assertSame(1, $context['metadata']['limits']['returned']);
        $this->assertSame('schedule', $context['metadata']['sources'][0]['source_type']);
        $this->assertSame(0.8432, $context['metadata']['sources'][0]['score']);
        $this->assertSame('2026-05-23T10:00:00+03:00', $context['metadata']['sources'][0]['updated_at']);
        $this->assertSame('/projects/10/schedules/56', $context['metadata']['sources'][0]['navigation_target']['route']);
        $this->assertSame('Schedule source', $context['metadata']['sources'][0]['navigation_target']['state']['assistant_source']['title']);
    }

    public function test_empty_results_mark_context_unused_without_sources(): void
    {
        $context = (new RagPromptContextBuilder)->build('missing context', []);

        $this->assertSame('', $context['prompt']);
        $this->assertTrue($context['metadata']['enabled']);
        $this->assertFalse($context['metadata']['used']);
        $this->assertSame([], $context['metadata']['sources']);
        $this->assertSame(0, $context['metadata']['limits']['returned']);
    }

    public function test_builds_navigation_targets_for_expanded_sources(): void
    {
        config()->set('ai-assistant.rag.max_chunks', 80);

        $cases = [
            ['estimate', '55', 10, [], '/estimates/55'],
            ['estimate_section', '56', 10, ['estimate_id' => 55], '/estimates/55'],
            ['estimate_template', '7', null, [], '/templates/library'],
            ['estimate_library_item', '8', null, [], '/libraries'],
            ['normative_rate', '9', null, [], '/catalogs/estimate-positions'],
            ['estimate_catalog_item', '11', null, [], '/catalogs/estimate-positions'],
            ['construction_journal_entry', '21', 10, ['journal_id' => 3], '/journals/3/entries/21'],
            ['performance_act', '31', 10, [], '/acts/31'],
            ['payment_document', '41', 10, [], '/payments/documents/41'],
            ['quality_defect', '51', 10, [], '/quality-control/defects/51'],
            ['executive_document_set', '61', 10, [], '/executive-documentation/sets/61'],
            ['executive_document', '71', 10, ['document_set_id' => 6], '/executive-documentation/sets/6'],
            ['project_material_delivery', '81', 10, [], '/warehouse'],
            ['warehouse_balance', '82', 10, [], '/warehouse'],
            ['warehouse_movement', '83', 10, [], '/warehouse'],
            ['warehouse_project_allocation', '84', 10, [], '/warehouse'],
            ['asset_reservation', '85', 10, [], '/warehouse'],
            ['inventory_act', '86', null, [], '/warehouse'],
            ['warehouse_storage_cell', '87', null, [], '/warehouse'],
            ['warehouse_task', '88', 10, [], '/warehouse'],
            ['warehouse_asset', '89', 10, [], '/warehouse'],
            ['schedule_task', '90', 10, [], '/projects/10/schedules'],
            ['supplier_request', '91', 10, [], '/procurement'],
            ['supplier_proposal', '92', 10, [], '/procurement'],
            ['supplier_proposal_decision', '93', 10, [], '/procurement'],
            ['purchase_order', '94', 10, [], '/procurement'],
            ['purchase_receipt', '95', 10, [], '/procurement'],
            ['procurement_approval', '96', 10, [], '/procurement'],
            ['procurement_audit_event', '97', 10, [], '/procurement'],
            ['safety_incident', '101', 10, [], '/safety'],
            ['safety_violation', '102', 10, [], '/safety'],
            ['safety_work_permit', '103', 10, [], '/safety'],
            ['safety_briefing', '104', 10, [], '/safety'],
            ['safety_corrective_action', '105', 10, [], '/safety'],
            ['machinery_asset', '111', 10, [], '/machinery'],
            ['machinery_assignment', '112', 10, [], '/machinery'],
            ['machinery_shift_report', '113', 10, [], '/machinery'],
            ['machinery_downtime', '114', 10, [], '/machinery'],
            ['machinery_maintenance_order', '115', 10, [], '/machinery'],
            ['machinery_fuel_issue', '116', 10, [], '/machinery'],
            ['machinery_production_record', '117', 10, [], '/machinery'],
            ['production_labor_work_order', '121', 10, [], '/production-labor'],
            ['production_labor_work_order_line', '122', 10, [], '/production-labor'],
            ['production_labor_timesheet', '123', 10, [], '/production-labor'],
            ['production_labor_timesheet_entry', '124', 10, [], '/production-labor'],
            ['production_labor_output_entry', '125', 10, [], '/production-labor'],
            ['production_labor_payroll_accrual', '126', 10, [], '/production-labor'],
            ['change_management_rfi', '131', 10, [], '/change-management'],
            ['change_request', '132', 10, [], '/change-management'],
            ['change_claim', '133', 10, [], '/change-management'],
            ['change_impact', '134', 10, [], '/change-management'],
            ['change_approval', '135', 10, [], '/change-management'],
            ['variation_order', '136', 10, [], '/change-management'],
            ['project_location', '141', 10, [], '/handover-acceptance'],
            ['acceptance_scope', '142', 10, [], '/handover-acceptance'],
            ['acceptance_session', '143', 10, [], '/handover-acceptance'],
            ['acceptance_checklist', '144', 10, [], '/handover-acceptance'],
            ['acceptance_checklist_item', '145', 10, [], '/handover-acceptance'],
            ['acceptance_finding', '146', 10, [], '/handover-acceptance'],
            ['acceptance_signoff', '147', 10, [], '/handover-acceptance'],
            ['handover_package', '148', 10, [], '/handover-acceptance'],
            ['handover_package_document', '149', 10, [], '/handover-acceptance'],
        ];

        $results = array_map(
            static fn (array $case): RagSearchResult => new RagSearchResult(
                sourceType: 'test',
                entityType: $case[0],
                entityId: $case[1],
                projectId: $case[2],
                title: "Source {$case[0]}",
                excerpt: 'Evidence',
                similarity: 0.8,
                metadata: $case[3],
                updatedAt: null
            ),
            $cases
        );

        foreach (array_chunk($results, 20) as $batchIndex => $batch) {
            $context = (new RagPromptContextBuilder)->build('open source', $batch);
            foreach ($batch as $index => $result) {
                $this->assertSame($cases[$batchIndex * 20 + $index][4], $context['metadata']['sources'][$index]['navigation_target']['route']);
            }
        }
    }

    public function test_provider_receives_addressed_chunk_content_beyond_preview_without_copying_it_into_metadata(): void
    {
        config()->set('ai-assistant.rag.expanded_context_enabled', true);
        $content = str_repeat('Вводный текст. ', 60)."\nНе закрывать ограждение до приёмки основания.";
        $result = new RagSearchResult('file_document', 'assistant_document', '41', 10, 'Указания', 'Короткое описание.', 0.9,
            ['source_id' => 12, 'chunk_id' => 25, 'chunk_index' => 2, 'source_version' => 'v4', 'checksum' => 'source-checksum',
                'document_id' => 41, 'unit_id' => 51, 'unit_index' => 3, 'unit_type' => 'page', 'provenance' => ['page' => 3]],
            content: $content);

        $context = (new RagPromptContextBuilder)->build('условия приёмки', [$result]);
        $prompt = json_decode($context['prompt'], true, flags: JSON_THROW_ON_ERROR);
        $source = $context['metadata']['sources'][0];

        self::assertSame($content, $prompt['sources'][0]['content']);
        self::assertArrayNotHasKey('excerpt', $prompt['sources'][0]);
        self::assertSame('Короткое описание.', $source['excerpt']);
        self::assertArrayNotHasKey('content', $source);
        self::assertArrayNotHasKey('content', get_object_vars($result));
        self::assertStringNotContainsString('Не закрывать ограждение', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        self::assertSame(25, $source['chunk_id']);
        self::assertSame(2, $source['chunk_index']);
        self::assertSame(51, $source['unit_id']);
        self::assertSame(['page' => 3], $source['provenance']);
        self::assertSame('v4', $source['source_version']);
        self::assertSame('source-checksum', $source['checksum']);
        self::assertSame(['scope' => 'rag_chunk', 'offset' => 0, 'length' => mb_strlen($content),
            'total_length' => mb_strlen($content), 'truncated' => false], $source['content_range']);
        self::assertSame(25, $prompt['sources'][0]['chunk_id']);
        self::assertArrayNotHasKey('checksum', $prompt['sources'][0]);
        $response = SearchAssistantDocumentsTool::responseForSearch($context,
            ['status' => 'available', 'semantic_available' => true, 'lexical_used' => false]);
        self::assertSame($context['prompt'], $response['document_context']);
        self::assertSame(25, $response['source_refs'][0]['chunk_id']);
        self::assertSame(2, $response['source_refs'][0]['chunk_index']);
        self::assertArrayNotHasKey('content', $response['source_refs'][0]);
        self::assertArrayNotHasKey('excerpt', $response['source_refs'][0]);
    }

    public function test_unicode_content_budget_bounds_each_chunk_and_the_whole_context(): void
    {
        config()->set('ai-assistant.rag.expanded_context_enabled', true);
        $results = [];
        foreach (range(1, 8) as $index) {
            $results[] = new RagSearchResult('project', 'project', '10', 10, 'Проект', 'Кратко', 0.9,
                ['source_id' => 1, 'chunk_id' => $index, 'chunk_index' => $index - 1],
                content: str_repeat('Я', $index === 1 ? 2300 : 3000));
        }

        $context = (new RagPromptContextBuilder)->build('условия', $results);
        $prompt = json_decode($context['prompt'], true, flags: JSON_THROW_ON_ERROR);

        self::assertCount(6, $prompt['sources']);
        self::assertSame(12000, array_sum(array_map(static fn (array $source): int => mb_strlen($source['content']), $prompt['sources'])));
        self::assertSame(2300, mb_strlen($prompt['sources'][0]['content']));
        self::assertSame(2400, mb_strlen($prompt['sources'][1]['content']));
        self::assertSame(100, mb_strlen($prompt['sources'][5]['content']));
        self::assertSame(['scope' => 'rag_chunk', 'offset' => 0, 'length' => 100,
            'total_length' => 3000, 'truncated' => true], $prompt['sources'][5]['content_range']);
        self::assertSame([1, 2, 3, 4, 5, 6], array_column($prompt['sources'], 'chunk_id'));
        self::assertSame(12000, $context['metadata']['limits']['content_characters']);
        self::assertSame(6, $context['metadata']['limits']['returned']);
        self::assertSame(2, $context['metadata']['limits']['omitted_sources']);
        self::assertSame(5, $context['metadata']['limits']['truncated_sources']);
    }

    public function test_source_count_is_bounded_even_with_a_larger_configured_limit(): void
    {
        config()->set('ai-assistant.rag.expanded_context_enabled', true);
        config()->set('ai-assistant.rag.max_chunks', 1000);
        $result = new RagSearchResult('project', 'project', '10', 10, 'Проект', 'Кратко', 0.9, content: 'Текст');

        $context = (new RagPromptContextBuilder)->build('условия', array_fill(0, 100, $result));

        self::assertSame(20, $context['metadata']['limits']['requested']);
        self::assertCount(20, $context['metadata']['sources']);
        self::assertSame(80, $context['metadata']['limits']['omitted_sources']);
    }

    public function test_legacy_unit_read_fragment_keeps_its_offset_and_excerpt_contract(): void
    {
        $result = new RagSearchResult('file_document', 'assistant_document', '41', 10, 'Указания', 'Продолжение текста', 1.0,
            ['document_id' => 41, 'unit_id' => 51, 'text_offset' => 1200, 'text_length' => 18]);

        $context = (new RagPromptContextBuilder)->build('', [$result]);
        $prompt = json_decode($context['prompt'], true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('Продолжение текста', $prompt['sources'][0]['excerpt']);
        self::assertSame(1200, $prompt['sources'][0]['text_offset']);
        self::assertArrayNotHasKey('content', $prompt['sources'][0]);
        self::assertArrayNotHasKey('content_range', $prompt['sources'][0]);
    }

    public function test_default_and_disabled_context_keep_private_chunk_out_of_provider_and_tool_output(): void
    {
        $preview = str_repeat('Я', 340);
        $content = $preview."\nPRIVATE-FULL-CHUNK-GATE-SENTINEL";
        $result = new RagSearchResult('file_document', 'assistant_document', '41', 10, 'Указания', $preview, 0.9,
            ['source_id' => 12, 'chunk_id' => 25, 'chunk_index' => 2, 'document_id' => 41, 'unit_id' => 51],
            content: $content);
        self::assertSame($content, $result->providerContent());
        self::assertFalse(config()->has('ai-assistant.rag.expanded_context_enabled'));

        foreach ([null, false] as $enabled) {
            if ($enabled !== null) {
                config()->set('ai-assistant.rag.expanded_context_enabled', $enabled);
            }
            $context = (new RagPromptContextBuilder)->build('условия', [$result]);
            $prompt = json_decode($context['prompt'], true, flags: JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('excerpt', $prompt['sources'][0]);
            self::assertSame($preview, $prompt['sources'][0]['excerpt']);
            self::assertArrayNotHasKey('content', $prompt['sources'][0]);
            self::assertArrayNotHasKey('content_range', $prompt['sources'][0]);
            self::assertArrayNotHasKey('content_range', $context['metadata']['sources'][0]);
            self::assertSame(['requested' => 8, 'returned' => 1], $context['metadata']['limits']);
            $response = SearchAssistantDocumentsTool::responseForSearch($context,
                ['status' => 'available', 'semantic_available' => true, 'lexical_used' => false]);
            $provider = AssistantToolResultProjection::forProvider('search_assistant_documents', $response);
            self::assertStringNotContainsString('PRIVATE-FULL-CHUNK-GATE-SENTINEL',
                json_encode([$context, $response, $provider, $result], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $public = json_encode([$context, $response, $provider], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            foreach (['chunk_id', 'chunk_index', 'content_range'] as $field) {
                self::assertStringNotContainsString('"'.$field.'"', $public);
            }
        }
    }
}
