<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\AssistantExtendedDomainRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestProjectScope;
use Tests\TestCase;

final class AssistantOrganizationRecordVocabularyTest extends TestCase
{
    public function test_registered_child_entity_names_request_the_whole_organization(): void
    {
        foreach (['Все строки нарядов организации', 'Все события закупки организации',
            'Все активные строки нарядов нашей организации', 'По всем событиям закупки организации'] as $question) {
            self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
            self::assertSame(['project_id' => null], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => 52], 52, true), $question);
            $arguments = ['sections' => [['project_id' => 52, 'filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'active']]]]];
            $expected = $arguments;
            $expected['sections'][0]['project_id'] = null;
            self::assertSame($expected, AssistantRequestProjectScope::fromPlan(['request' => ['message' => $question,
                'context' => ['entity_refs' => [['entity_type' => 'project', 'entity_id' => 52]]]]])->bindReportScope($question, $arguments), $question);
        }
    }

    public function test_every_registered_russian_caption_has_organization_scope(): void
    {
        $translated = app('translator')->get('ai_assistant_facts.entities', [], 'ru');
        $labels = array_replace(is_array($translated) ? $translated : [], AssistantExtendedDomainRegistry::values('entityLabels'));
        $registered = [];
        foreach (app(AssistantDomainCatalog::class)->all() as $definition) {
            foreach ($definition->entityTypes as $type) {
                $registered[$type] = true;
            }
        }
        self::assertGreaterThan(500, count($registered));
        $failures = [];
        foreach (array_keys($registered) as $type) {
            self::assertIsString($labels[$type] ?? null, $type);
            $label = $labels[$type];
            $question = 'Покажи все '.$label.' организации';
            if (! AssistantRequestProjectScope::organizationWide($question)
                || AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => 52], 52, true) !== ['project_id' => null]) {
                $failures[] = $type.': '.$label;
            }
            foreach (['Не показывай все '.$label.' организации', 'Покажи сведения проекта «Все '.$label.' организации»',
                'Покажи сведения проекта Все '.$label.' организации'] as $negative) {
                if (AssistantRequestProjectScope::organizationWide($negative)) {
                    $failures[] = 'negative '.$type.': '.$negative;
                }
            }
        }
        self::assertSame([], $failures);
    }

    public function test_catalog_words_keep_process_foreign_organization_and_unknown_entity_boundaries(): void
    {
        foreach (['Все строки нарядов другой организации', 'Все события закупки организаций',
            'Все события закупки организации строительства', 'Все строки нарядов организации работ',
            'Все финансовые организации', 'Все неизвестные сущности организации',
            'Не показывай все события закупки организации', 'Все события закупки организации, только выбранного проекта'] as $question) {
            self::assertFalse(AssistantRequestProjectScope::organizationWide($question), $question);
        }
    }
}
