<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequiredProjectRead;
use App\BusinessModules\Features\AIAssistant\Services\AssistantToolSurface;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOperationalReaderRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantRagIndexStatusReader;
use PHPUnit\Framework\TestCase;

final class AssistantOrganizationDataStatusIntentTest extends TestCase
{
    public function test_status_questions_bind_organization_scope_even_with_a_selected_project(): void
    {
        foreach ([
            'Какие данные по всей нашей организации уже доступны тебе для поиска? Проверь состояние базы знаний и очереди индексации, покажи подтверждённые количества документов и фрагментов. Если статистика пока не готова, прямо скажи. Ничего не переиндексируй.',
            'Сколько документов и фрагментов в базе знаний? Есть ли очередь индексации?',
            'Покажи статистику базы знаний по организации. Ничего не обновляй.',
            'Проверь состояние базы знаний по всем проектам организации.',
        ] as $query) {
            $plan = ['request' => ['message' => $query,
                'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
            self::assertSame(['name' => 'get_rag_index_status', 'arguments' => ['scope' => 'organization']], AssistantRequiredProjectRead::fromPlan($plan), $query);
            self::assertSame(['scope' => 'organization'], AssistantRequiredProjectRead::bindArguments($plan, 'get_rag_index_status', ['scope' => 'project']));
            self::assertContains('get_rag_index_status', AssistantToolSurface::names(['get_rag_index_status'], $plan));
            self::assertSame(AssistantOperationalReaderRegistry::ORGANIZATION_TOOLS['get_rag_index_status'], AssistantRequiredProjectRead::decisionReader('get_rag_index_status', $query));
        }
    }

    public function test_status_does_not_replace_content_money_actions_files_or_a_named_project(): void
    {
        foreach ([
            'Проверь состояние базы знаний и перескажи содержание документов.',
            'Сколько документов в базе знаний и что написано в договоре?',
            'Покажи статистику базы знаний и выгрузи XLSX.',
            'Проверь состояние базы знаний и запусти переиндексацию.',
            'Сколько источников в индексе проекта 102?',
            'Проверь состояние базы знаний и расходы организации.',
            'Покажи статистику базы знаний всей организации и проекта «Северный дом».',
            'Проверь состояние базы знаний по всем проектам, кроме проекта «Северный дом».',
            'Покажи готовые документы из базы знаний.',
            'Покажи доступные для поиска документы из базы знаний.',
            'Покажи состояние базы знаний и прибыль организации.',
            'Покажи состояние базы знаний и скажи, подтверждена ли наша организация.',
            'Проверь состояние базы знаний и процитируй документы.',
            'Проверь состояние базы знаний и подготовь документы.',
        ] as $query) {
            self::assertFalse(AssistantRagIndexStatusReader::matches($query), $query);
            self::assertNull(AssistantRequiredProjectRead::decisionReader('get_rag_index_status', $query), $query);
        }
    }

    public function test_document_corpus_guards_remain_active_for_mixed_content_requests(): void
    {
        $service = (new \ReflectionClass(AIAssistantService::class))->newInstanceWithoutConstructor();
        $corpus = new \ReflectionMethod($service, 'shouldCheckDocumentCorpus');
        $evidence = new \ReflectionMethod($service, 'documentEvidenceRequiredForAnswer');
        foreach (['Проверь состояние базы знаний и перескажи содержание документов.',
            'Сколько документов в базе знаний и что написано в договоре?',
            'Проверь состояние базы знаний и процитируй документы.'] as $query) {
            self::assertTrue($corpus->invoke($service, $query, []), $query);
            self::assertTrue($evidence->invoke($service, $query), $query);
        }
        $query = 'Сколько документов и фрагментов в базе знаний? Есть ли очередь индексации?';
        self::assertFalse($corpus->invoke($service, $query, []));
        self::assertFalse($evidence->invoke($service, $query));
    }
}
