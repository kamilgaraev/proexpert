<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantRequiredProjectRead;
use App\BusinessModules\Features\AIAssistant\Services\AssistantToolSurface;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantContractSupplementaryReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOperationalReaderRegistry;
use PHPUnit\Framework\TestCase;

final class AssistantOrganizationCoverageIntentTest extends TestCase
{
    public function test_organization_and_personal_queries_bind_current_scope_and_expose_tools(): void
    {
        foreach ([
            ['Какие настройки подотчётных средств сейчас действуют у нашей организации? Назови лимит одной выдачи, срок сдачи авансового отчёта и правила согласования и уведомлений. Ничего не меняй.', 'get_organization_advance_settings'],
            ['Посмотри по всей нашей организации: сколько у нас активных проектов и сколько архивных? Назови активные проекты и их плановые даты окончания.', 'get_organization_project_roster'],
            ['Какие поставщики есть в справочнике нашей организации? Сколько всего и как быстро найти нужного по названию?', 'get_organization_supplier_roster'],
            ['По всей нашей организации какие действия выполняли за последние семь дней? Сколько событий и кто вносил последние изменения?', 'get_organization_activity_summary'],
            ['Покажи журнал событий по всей нашей организации за последние 7 дней.', 'get_organization_activity_summary'],
            ['Сколько у меня сейчас непрочитанных уведомлений? Покажи самые свежие и подскажи, что требует моего внимания.', 'get_organization_notifications'],
        ] as [$query, $tool]) {
            $plan = $this->plan($query);
            self::assertSame(['name' => $tool, 'arguments' => ['scope' => 'organization']], AssistantRequiredProjectRead::fromPlan($plan), $query);
            self::assertSame(['scope' => 'organization'], AssistantRequiredProjectRead::bindArguments($plan, $tool, ['scope' => 'project']));
            self::assertContains($tool, AssistantToolSurface::names([$tool], $plan));
            self::assertSame(AssistantOperationalReaderRegistry::ORGANIZATION_TOOLS[$tool], AssistantRequiredProjectRead::decisionReader($tool, $query));
        }
    }

    public function test_named_contract_supplements_use_exact_number_and_do_not_inherit_selected_project(): void
    {
        $query = 'По договору ДП-202608-34 есть дополнительные соглашения? Какие из них ещё не вступили в силу и что надо проверить перед активацией?';
        self::assertSame(['name' => 'get_contract_supplementary_readiness', 'arguments' => ['contract_number' => 'ДП-202608-34', 'project_id' => null, 'project_query' => null]], AssistantRequiredProjectRead::fromPlan($this->plan($query)));
        self::assertSame(['contract_number' => 'SUP-52', 'project_id' => null, 'project_query' => 'СевГУ'],
            AssistantRequiredProjectRead::fromPlan($this->plan('Допсоглашения договора SUP-52 по проекту СевГУ?'))['arguments']);
        self::assertSame('ДП-202608-34', AssistantContractSupplementaryReader::contractNumber('Договор № ДП–202608–34, допсоглашения?'));
        self::assertNull(AssistantContractSupplementaryReader::contractNumber('По договору нашей организации есть допсоглашения?'));
        self::assertSame('52', AssistantContractSupplementaryReader::projectQuery('Допсоглашения договора SUP-52 по проекту №52?'));
    }

    public function test_mixed_domain_and_mutation_queries_are_not_replaced_by_one_summary(): void
    {
        foreach (['По всей организации какие активные проекты и какой бюджет?', 'Какие поставщики в справочнике организации и какие счета оплатить?',
            'Измени настройки подотчётных средств нашей организации.', 'Какие настройки подотчётных средств и какие счета оплатить?',
            'Какие настройки подотчётных средств и мои непрочитанные уведомления?',
            'Увеличь лимит выдачи подотчётных средств', 'Уменьши срок сдачи авансового отчёта',
            'Настройте лимит подотчётных средств', 'Настроить лимит подотчётных средств',
            'Снизь лимит выдачи подотчётных средств', 'Повысь лимит выдачи подотчётных средств',
            'Задай лимит выдачи подотчётных средств 10000', 'Выставь лимит подотчётных средств 10000',
            'Подправь лимит подотчётных средств',
            'Покажи настройки подотчётных средств и сделай лимит 10000',
            'Покажи настройки подотчётных средств и поставь лимит 10000',
            'Покажи настройки подотчётных средств и скорректируй лимит 10000',
            'По всей нашей организации активные проекты и сотрудники на них?', 'Мои непрочитанные уведомления и события организации за последние семь дней',
            'По договору ДП-202608-34 допсоглашения и какие акты ждут согласования?', 'Активируй дополнительные соглашения договора ДП-202608-34',
            'Сколько у меня непрочитанных уведомлений? Пометь их прочитанными.',
            'Покажи допсоглашения договора SUP-52 и открытые CRM-сделки нашего объекта',
            'Допсоглашения договора SUP-52 и какие доступные отчёты помогут руководителю?'] as $query) {
            self::assertNull(AssistantOperationalReaderRegistry::intent($query), $query);
            self::assertNull(AssistantRequiredProjectRead::fromPlan($this->plan($query)), $query);
            self::assertNull(AssistantRequiredProjectRead::decisionReader('get_contract_supplementary_readiness', $query), $query);
        }
    }

    private function plan(string $query): array
    {
        return ['request' => ['message' => $query, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]];
    }
}
