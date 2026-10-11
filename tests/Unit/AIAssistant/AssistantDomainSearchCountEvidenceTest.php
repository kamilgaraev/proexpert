<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainSearchCountEvidence;
use App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactVerifier;
use App\Models\User;
use PHPUnit\Framework\TestCase;

final class AssistantDomainSearchCountEvidenceTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_natural_record_count_uses_full_checked_total_instead_of_page_size_including_zero(): void
    {
        foreach ([24, 0] as $total) {
            $evidence = $this->evidence($total);
            $modelText = $total === 0 ? '{"kind":"verified_rows","version":1,"result_sets":[]}' : 'Найдено 20.';
            $result = (new AssistantStructuredFactVerifier)->guard('Сколько договоров в организации?', $modelText, [[
                'result_window' => ['returned' => min(20, $total), 'total' => $total, 'has_more' => $total > 20],
                'domain_search_count_evidence' => $evidence,
            ]]);

            self::assertSame(trans_message('ai_assistant_facts.search_record_count_organization', ['count' => $total]), $result['text']);
            self::assertSame('verified', $result['validation_status']);
            self::assertSame([$evidence['source_ref']], $result['source_refs']);
            self::assertFalse($result['structured_evidence_truncated']);
            self::assertFalse($result['needs_clarification']);
        }
    }

    public function test_unsigned_page_count_and_tampered_receipts_cannot_make_a_verified_count(): void
    {
        $evidence = $this->evidence(24);
        self::assertTrue(AssistantDomainSearchCountEvidence::trusted($evidence));
        $scope = $evidence['source_ref']['_search_count_query'];
        foreach ([['value', 20], ['record_count', 20], ['organization_id', 9], ['_search_count_actor', '9'],
            ['_search_count_proof', str_repeat('0', 64)], ['checked_fields', ['record_count', 'total_amount']],
            ['required_permissions', []], ['required_domains', []],
            ['_search_count_query', array_replace($scope, ['query' => 'other'])],
            ['_search_count_query', array_replace($scope, ['project_id' => 9])],
            ['_search_count_query', array_replace($scope, ['fields' => ['total_amount']])],
            ['fetched_at', '2099-01-01T00:00:00Z']] as [$field, $value]) {
            $changed = $evidence;
            if ($field === 'value') { $changed[$field] = $value; } else { $changed['source_ref'][$field] = $value; }
            self::assertFalse(AssistantDomainSearchCountEvidence::trusted($changed), $field);
        }
        $result = (new AssistantStructuredFactVerifier)->guard('Сколько договоров?', 'Всего 20.', [['result_window' => ['total' => 20]]]);
        self::assertTrue($result['needs_clarification']);
        self::assertSame([], $result['source_refs']);
        self::assertStringNotContainsString('20', $result['text']);
    }

    public function test_current_count_scope_and_freshness_must_match_original_receipt(): void
    {
        $first = $this->evidence(24);
        $current = $this->evidence(24, '-1 minute');
        self::assertTrue(AssistantDomainSearchCountEvidence::matchesCurrentEvidence($first['source_ref'], $current));
        self::assertFalse(AssistantDomainSearchCountEvidence::matchesCurrentEvidence($first['source_ref'], $this->evidence(23)));
        self::assertFalse(AssistantDomainSearchCountEvidence::trusted($this->evidence(24, '-11 minutes')));
        $other = $this->evidence(24, 'now', 2);
        self::assertFalse(AssistantDomainSearchCountEvidence::matchesCurrentEvidence($first['source_ref'], $other));
        $actor = new User;
        $actor->setAttribute('id', 9);
        $helper = new AssistantDomainSearchCountEvidence;
        self::assertFalse($helper->matchesReference($actor, 1, $first['source_ref']));
        $actor->setAttribute('id', 3);
        self::assertFalse($helper->canReadReference($actor, 9, $first['source_ref']));
        config(['app.key' => 'changed-test-key']);
        self::assertFalse(AssistantDomainSearchCountEvidence::trusted($first));
    }

    public function test_money_or_quantity_requests_do_not_use_record_count_as_money_or_stock(): void
    {
        foreach (['Сколько стоят договоры?', 'Сколько денег по договорам?', 'Количество материала на складе',
            'Сколько рублей оплачено по счетам?', 'Покажи таблицу договоров'] as $query) {
            self::assertFalse(AssistantDomainSearchCountEvidence::isCountRequest($query));
        }
        self::assertFalse(AssistantDomainSearchCountEvidence::matchesRequestedEntity('Сколько проектов?', $this->evidence(24)));
        $result = (new AssistantStructuredFactVerifier)->guard('Сколько проектов?', 'Всего 24.', [['domain_search_count_evidence' => $this->evidence(24)]]);
        self::assertTrue($result['needs_clarification']);
        self::assertSame([], $result['source_refs']);
    }

    public function test_available_catalogue_defects_suppliers_and_equipment_receive_their_checked_counts(): void
    {
        foreach ([['quality_defect', 'Сколько дефектов найдено?', 24], ['supplier', 'Количество поставщиков', 0],
            ['core_journal_equipment', 'Сколько записей оборудования?', 31]] as [$type, $query, $count]) {
            $evidence = $this->evidence($count, 'now', 1, $type);
            $result = (new AssistantStructuredFactVerifier)->guard($query, 'Найдено 20.', [['domain_search_count_evidence' => $evidence]]);

            self::assertSame('verified', $result['validation_status']);
            self::assertSame(trans_message('ai_assistant_facts.search_record_count', ['count' => $count]), $result['text']);
            self::assertSame([$evidence['source_ref']], $result['source_refs']);
        }
    }

    public function test_signed_project_count_cannot_answer_positions_employees_documents_or_unknown_target(): void
    {
        foreach (['Сколько позиций в смете?', 'Сколько сотрудников в проекте?', 'Сколько документов?',
            'Сколько договоров по проекту?', 'Сколько неизвестных сущностей?', 'Сколько в организации?', 'Число 10?'] as $query) {
            $result = (new AssistantStructuredFactVerifier)->guard($query, 'Всего 7.', [['domain_search_count_evidence' => $this->evidence(7, 'now', 1, 'project')]]);

            self::assertTrue($result['needs_clarification'], $query);
            self::assertSame([], $result['source_refs']);
            self::assertStringNotContainsString('7', $result['text']);
        }
    }

    public function test_money_balances_and_material_measurements_never_use_record_count_receipt(): void
    {
        foreach (['Сколько денег на счетах?', 'Сколько на счете денег?', 'Количество поставленного материала',
            'Сколько материала на складе?', 'Сколько тонн оборудования?', 'Количество кг поставки',
            'Сколько кубометров бетона?', 'Какой объём поставок?', 'Количество литров топлива', 'Сколько осталось?'] as $query) {
            self::assertFalse(AssistantDomainSearchCountEvidence::isCountRequest($query), $query);
        }
    }

    public function test_record_count_intent_accepts_every_registered_entity_scope_without_a_noun_whitelist(): void
    {
        config(['app.key' => 'search-count-test-key']);
        $actor = new User;
        $actor->setAttribute('id', 3);
        $catalog = new AssistantDomainCatalog(AssistantDomainCatalog::defaults());
        app()->instance(AssistantDomainCatalog::class, $catalog);
        $checked = [];
        foreach ($catalog->all() as $definition) {
            foreach ($definition->entityTypes as $type) {
                $evidence = AssistantDomainSearchCountEvidence::payload($actor, 1, $definition, $type,
                    ['query' => ''], [$definition->fields[0]], 37, (new \DateTimeImmutable)->format(DATE_ATOM));
                self::assertTrue(AssistantDomainSearchCountEvidence::matchesRequestedEntity('Сколько записей найдено?', $evidence), $type);
                self::assertTrue(AssistantDomainSearchCountEvidence::matchesRequestedScope('Сколько записей найдено?', $evidence), $type);
                $checked[$type] = true;
            }
        }
        self::assertCount(556, $checked);
    }

    public function test_requested_catalogue_label_and_context_scope_do_not_accept_other_signed_entity_counts(): void
    {
        foreach ([['supplier', 'Сколько дефектов найдено?'], ['quality_defect', 'Количество поставщиков'],
            ['project', 'Сколько записей оборудования в журнале?'], ['contract', 'Сколько сотрудников в проекте?']] as [$type, $query]) {
            $evidence = $this->evidence(17, 'now', 1, $type);
            self::assertFalse(AssistantDomainSearchCountEvidence::matchesRequestedEntity($query, $evidence));
            $result = (new AssistantStructuredFactVerifier)->guard($query, 'Всего 17.', [['domain_search_count_evidence' => $evidence]]);
            self::assertTrue($result['needs_clarification']);
            self::assertSame([], $result['source_refs']);
        }
    }

    public function test_ambiguous_document_target_does_not_choose_one_available_document_kind(): void
    {
        $definitions = array_values(array_filter(AssistantDomainCatalog::defaults(), static fn ($definition): bool =>
            array_intersect($definition->entityTypes, ['design_artifact', 'executive_document']) !== []));
        app()->instance(AssistantDomainCatalog::class, new AssistantDomainCatalog($definitions));
        foreach (['design_artifact', 'executive_document'] as $type) {
            $evidence = $this->evidence(12, 'now', 1, $type);
            self::assertFalse(AssistantDomainSearchCountEvidence::matchesRequestedEntity('Сколько документов?', $evidence));
            $result = (new AssistantStructuredFactVerifier)->guard('Сколько документов?', 'Всего 12.', [['domain_search_count_evidence' => $evidence]]);
            self::assertTrue($result['needs_clarification']);
            self::assertSame([], $result['source_refs']);
        }
    }

    public function test_organization_count_cannot_answer_unconfirmed_project_status_text_or_period_constraints(): void
    {
        foreach (['Сколько договоров в текущем проекте?', 'Сколько активных договоров?',
            'Сколько договоров с названием «МОСТ»?', 'Сколько договоров по поиску «МОСТ»?',
            'Сколько договоров за 2025 год?', 'Сколько договоров в организации Север?'] as $query) {
            $result = (new AssistantStructuredFactVerifier)->guard($query, 'Всего 7.', [['domain_search_count_evidence' => $this->evidence(7)]]);

            self::assertTrue($result['needs_clarification'], $query);
            self::assertSame([], $result['source_refs']);
            self::assertStringNotContainsString('7', $result['text']);
        }
    }

    public function test_model_chosen_project_count_requires_matching_validated_request_project(): void
    {
        $evidence = $this->evidence(7, 'now', 1, 'contract', ['project_id' => 9]);
        foreach ([[], ['project_id' => 10], ['project_id' => 0], ['project_id' => '9']] as $requestScope) {
            $result = (new AssistantStructuredFactVerifier)->guard('Сколько договоров в текущем проекте?', 'Всего 7.',
                [['domain_search_count_evidence' => $evidence]], $requestScope);
            self::assertTrue($result['needs_clarification']);
            self::assertSame([], $result['source_refs']);
        }
    }

    public function test_matching_validated_current_project_count_is_verified_and_scope_is_named(): void
    {
        $evidence = $this->evidence(7, 'now', 1, 'contract', ['project_id' => 9]);
        $results = [['domain_search_count_evidence' => $evidence]];
        $verifier = new AssistantStructuredFactVerifier;
        $guarded = $verifier->guard('Сколько договоров в текущем проекте?', 'Всего 20.', $results, ['project_id' => 9]);
        $confirmed = $verifier->confirmedResults($results, 'Сколько договоров в текущем проекте?', ['project_id' => 9]);
        foreach ([$guarded, $confirmed] as $result) {
            self::assertSame('verified', $result['validation_status']);
            self::assertFalse($result['needs_clarification']);
            self::assertSame(trans_message('ai_assistant_facts.search_record_count_project', ['count' => 7]), $result['text']);
        }
        $named = $verifier->guard('Сколько договоров в проекте Север?', 'Всего 7.', $results, ['project_id' => 9]);
        self::assertTrue($named['needs_clarification']);
        self::assertSame([], $named['source_refs']);
    }

    public function test_literal_search_count_must_match_requested_text_and_extra_model_filters_are_rejected(): void
    {
        $evidence = $this->evidence(7, 'now', 1, 'contract', ['query' => 'МОСТ']);
        $verifier = new AssistantStructuredFactVerifier;
        $positive = $verifier->guard('Сколько договоров по поиску «МОСТ»?', 'Всего 7.', [['domain_search_count_evidence' => $evidence]]);
        self::assertSame('verified', $positive['validation_status']);
        foreach (['Сколько договоров?', 'Сколько договоров по поиску «Север»?', 'Сколько договоров с названием «МОСТ»?',
            'Сколько активных договоров по поиску «МОСТ»?'] as $query) {
            $result = $verifier->guard($query, 'Всего 7.', [['domain_search_count_evidence' => $evidence]]);
            self::assertTrue($result['needs_clarification'], $query);
            self::assertSame([], $result['source_refs']);
        }
    }

    public function test_explicit_organization_scope_overrides_project_context_and_rejects_project_receipt(): void
    {
        $verifier = new AssistantStructuredFactVerifier;
        $organization = $verifier->guard('Сколько договоров во всей организации?', 'Всего 7.',
            [['domain_search_count_evidence' => $this->evidence(7)]], ['project_id' => 9]);
        self::assertSame('verified', $organization['validation_status']);
        self::assertSame(trans_message('ai_assistant_facts.search_record_count_organization', ['count' => 7]), $organization['text']);
        $project = $verifier->guard('Сколько договоров во всей организации?', 'Всего 7.',
            [['domain_search_count_evidence' => $this->evidence(7, 'now', 1, 'contract', ['project_id' => 9])]], ['project_id' => 9]);
        self::assertTrue($project['needs_clarification']);
        self::assertSame([], $project['source_refs']);
        $implicit = $verifier->guard('Сколько договоров?', 'Всего 7.',
            [['domain_search_count_evidence' => $this->evidence(7, 'now', 1, 'contract', ['project_id' => 9])]], ['project_id' => 9]);
        self::assertSame(trans_message('ai_assistant_facts.search_record_count_project', ['count' => 7]), $implicit['text']);
        self::assertStringNotContainsString('организации', $implicit['text']);
    }

    public function test_other_parent_scopes_priority_and_color_cannot_use_unfiltered_signed_entity_totals(): void
    {
        foreach ([['estimate_generation_element_card', 'Сколько элементов в модели Севгу?'],
            ['design_ifc_model_element', 'Сколько элементов BIM-модели в модели Севгу?'],
            ['estimate_item', 'Сколько позиций сметы в этой смете?'],
            ['core_journal_equipment', 'Сколько оборудования на складе?'],
            ['quality_defect', 'Сколько дефектов с высоким приоритетом?'],
            ['quality_defect', 'Сколько красных дефектов?']] as [$type, $query]) {
            $evidence = $this->evidence(7, 'now', 1, $type);
            $result = (new AssistantStructuredFactVerifier)->guard($query, 'Всего 7.', [['domain_search_count_evidence' => $evidence]]);

            self::assertTrue($result['needs_clarification'], $query);
            self::assertSame([], $result['source_refs']);
            self::assertStringNotContainsString('7', $result['text']);
        }
    }

    public function test_unqualified_count_target_words_and_polite_prefixes_keep_genuine_receipts(): void
    {
        foreach ([['quality_defect', 'Подскажи, сколько дефектов найдено сейчас?'],
            ['supplier', 'Количество доступных поставщиков'], ['core_journal_equipment', 'Сколько записей оборудования?']] as [$type, $query]) {
            $result = (new AssistantStructuredFactVerifier)->guard($query, 'Всего 7.',
                [['domain_search_count_evidence' => $this->evidence(7, 'now', 1, $type)]]);
            self::assertSame('verified', $result['validation_status']);
            self::assertFalse($result['needs_clarification']);
            self::assertCount(1, $result['source_refs']);
        }
    }

    private function evidence(int $total, string $time = 'now', int $organizationId = 1, string $entityType = 'contract', array $arguments = []): array
    {
        config(['app.key' => 'search-count-test-key']);
        $actor = new User;
        $actor->setAttribute('id', 3);
        $definition = null;
        foreach ((new AssistantDomainCatalog(AssistantDomainCatalog::defaults()))->all() as $candidate) {
            if (in_array($entityType, $candidate->entityTypes, true)) { $definition = $candidate; break; }
        }
        self::assertNotNull($definition);

        return AssistantDomainSearchCountEvidence::payload($actor, $organizationId, $definition, $entityType,
            $arguments + ['query' => '', 'project_id' => null], [$definition->fields[0]], $total, (new \DateTimeImmutable($time))->format(DATE_ATOM));
    }
}
