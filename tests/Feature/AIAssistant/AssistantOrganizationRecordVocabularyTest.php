<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\AssistantExtendedDomainRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantOrganizationRecordVocabulary;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestProjectScope;
use Tests\TestCase;

final class AssistantOrganizationRecordVocabularyTest extends TestCase
{
    public function test_registered_entity_phrases_can_precede_explicit_project_comparison_operands(): void
    {
        foreach (['актами', 'утверждёнными актами', 'строками нарядов', 'событиями закупки'] as $phrase) {
            self::assertTrue(AssistantOrganizationRecordVocabulary::matchesEntityPhrase($phrase), $phrase);
            foreach (['проекта 100' => 100, 'выбранного проекта' => 52] as $project => $id) {
                $question = 'Сравни все акты организации с '.$phrase.' '.$project;
                self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
                self::assertSame(['project_id' => null], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => null], 52, true));
                self::assertSame(['project_id' => $id], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => $id], 52, true));
            }
        }
        foreach (['не актами', 'без актов', '«актами»', 'актами, договорами', 'неизвестными сущностями', 'актами проекта'] as $phrase) {
            self::assertFalse(AssistantOrganizationRecordVocabulary::matchesEntityPhrase($phrase), $phrase);
        }
    }

    public function test_registered_child_entity_names_request_the_whole_organization(): void
    {
        foreach (['Все строки нарядов организации', 'Все события закупки организации',
            'Все активные строки нарядов нашей организации', 'По всем событиям закупки организации',
            'Подготовь отчёт со всеми строками нарядов организации',
            'Подготовь отчёт со всеми событиями закупки организации'] as $question) {
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
            foreach (['Покажи все '.$label.' организации', 'Отчёт со всеми '.$label.' организации'] as $question) {
                if (! AssistantRequestProjectScope::organizationWide($question)
                    || AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => 52], 52, true) !== ['project_id' => null]) {
                    $failures[] = $type.': '.$question;
                }
            }
            $comparison = 'Сравни выбранный проект со всеми '.$label.' организации';
            if (! AssistantRequestProjectScope::organizationWide($comparison)
                || AssistantRequestProjectScope::fromPlan(['request' => ['message' => $comparison]])->requested
                || AssistantRequestProjectScope::bindSearchScope($comparison, ['project_id' => null], 52, true) !== ['project_id' => null]
                || AssistantRequestProjectScope::bindSearchScope($comparison, ['project_id' => 52], 52, true) !== ['project_id' => 52]) {
                $failures[] = 'comparison '.$type.': '.$comparison;
            }
            foreach (['Не показывай все '.$label.' организации', 'Покажи сведения проекта «Все '.$label.' организации»',
                'Покажи сведения проекта Все '.$label.' организации',
                'Покажи сведения проекта «Со всеми '.$label.' организации»',
                'Покажи сведения проекта Со всеми '.$label.' организации'] as $negative) {
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
