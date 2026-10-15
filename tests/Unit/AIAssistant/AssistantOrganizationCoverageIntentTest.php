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
    public function test_advance_topic_exposes_settings_without_forcing_an_unrelated_answer(): void
    {
        $query = 'Подотчёт: что мне посмотреть?';
        $tool = 'get_organization_advance_settings';
        self::assertTrue(\App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantAdvanceSettingsReader::hasIntent($query));
        self::assertNull(AssistantRequiredProjectRead::fromPlan($this->plan($query)));
        self::assertContains($tool, AssistantToolSurface::names([$tool], $this->plan($query)));
    }

    public function test_organization_and_personal_queries_bind_current_scope_and_expose_tools(): void
    {
        foreach ([
            ['Какие настройки подотчёта действуют по состоянию на сегодня?', 'get_organization_advance_settings'],
            ['Уведомляют ли сотрудника об отклонении подотчётной операции?', 'get_organization_advance_settings'],
            ['Уведомляют ли сотрудника об одобрении подотчётной операции?', 'get_organization_advance_settings'],
            ['Сколько составляет лимит подотчётных средств — 5000 рублей?', 'get_organization_advance_settings'],
            ['Какой срок подотчёта?', 'get_organization_advance_settings'],
            ['Сколько дней даётся на подотчёт?', 'get_organization_advance_settings'],
            ['Уведомляют ли администратора о просроченном авансовом отчёте?', 'get_organization_advance_settings'],
            ['Будут ли уведомлять администратора о просроченном авансовом отчёте?', 'get_organization_advance_settings'],
            ['Обязательно ли указывать проект для подотчётного расхода?', 'get_organization_advance_settings'],
            ['Требуется ли проект для подотчётного расхода?', 'get_organization_advance_settings'],
            ['Нужно ли указывать проект для подотчётного расхода?', 'get_organization_advance_settings'],
            ['Надо ли указывать проект для подотчётного расхода?', 'get_organization_advance_settings'],
            ['Напоминают ли об авансовом отчёте?', 'get_organization_advance_settings'],
            ['За сколько дней напоминают об авансовом отчёте?', 'get_organization_advance_settings'],
            ['Какой порог двойного согласования подотчётных средств?', 'get_organization_advance_settings'],
            ['Какой порог уведомления о крупной операции подотчётных средств?', 'get_organization_advance_settings'],
            ['Какие пороги согласования подотчётных средств действуют?', 'get_organization_advance_settings'],
            ['Какие настройки подотчётных средств сейчас действуют у нашей организации? Назови лимит одной выдачи, срок сдачи авансового отчёта и правила согласования и уведомлений. Ничего не меняй.', 'get_organization_advance_settings'],
            ['Какова максимальная сумма одной выдачи подотчётных средств?', 'get_organization_advance_settings'],
            ['Какова максимальная сумма для одной выдачи подотчётных средств?', 'get_organization_advance_settings'],
            ['Сколько дней даётся для сдачи авансового отчёта?', 'get_organization_advance_settings'],
            ['Какое количество дней составляет срок сдачи авансового отчёта?', 'get_organization_advance_settings'],
            ['Расскажи о настройках уведомлений администратора о просроченном авансовом отчёте', 'get_organization_advance_settings'],
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
            'Что делать, если просрочен срок сдачи авансового отчёта?',
            'Что будет, если превысить лимит подотчётных средств?',
            'Какие авансовые отчёты просрочили срок сдачи?',
            'Покажи авансовые отчёты, которые просрочили срок сдачи',
            'Сколько дней осталось до срока сдачи авансового отчёта №123?',
            'Какой срок сдачи авансового отчёта №123?',
            'Каков порядок согласования авансового отчёта?',
            'Какие правила согласования авансового отчёта действуют?',
            'Обязательно ли авансовый отчёт согласовывать?',
            'Нужно ли согласовывать авансовый отчёт?',
            'Обязательно ли согласование и проверка авансового отчёта?',
            'Какой лимит подотчётных средств действовал в сентябре?',
            'Какие настройки подотчёта были в прошлом месяце?',
            'Какие настройки подотчёта были вчера?',
            'Покажи настройки подотчёта через неделю',
            'Покажи настройки подотчёта неделю назад',
            'Покажи настройки подотчёта на следующий квартал',
            'Покажи лимит подотчёта на следующую пятницу',
            'Какие правила выдачи подотчётных средств?',
            'Расскажи правила возврата подотчётных средств',
            'Будет ли администратор уведомлен об одобрении авансового отчёта?',
            'Будет ли администратор уведомлен об утверждении авансового отчёта?',
            'Будет ли администратор уведомлен об отклонении авансового отчёта?',
            'Сколько подотчётных средств уже выдано в рамках лимита?',
            'Сколько раз можно превысить лимит подотчётных средств?',
            'Какой лимит подотчётных средств для проекта Север?',
            'Какой лимит подотчётных средств установлен для Иванова?',
            'Какой лимит подотчётных средств установлен для иванова?',
            'Какой лимит подотчётных средств у меня?',
            'Какой лимит подотчётных средств для организации Ромашка?',
            'Какой лимит подотчётных средств организации Ромашка?',
            'Какой лимит подотчётных средств в организации Ромашка?',
            'Какой лимит подотчётных средств для организации 42?',
            'Какой лимит подотчётных средств в организации 42?',
            'Какой лимит количества позиций в авансовом отчёте?',
            'Какие настройки печати авансового отчёта?',
            'Уведомляют ли администратора о выдаче подотчётных средств?',
            'Уведомляют ли администратора о создании подотчётной операции?',
            'Уведомляют ли администратора об отклонении подотчётной операции?',
            'Уведомляют ли администратора о просроченном авансовом отчёте и о создании подотчётной операции?',
            'Уведомляют ли администратора о просроченном авансовом отчёте и создании подотчётной операции?',
            'Какова максимальная сумма уже выданных подотчётных средств?',
            'Какой суточный лимит подотчётных средств?',
            'Какой месячный лимит подотчётных средств?',
            'Какой лимит подотчётных средств месячный?',
            'Какой лимит подотчётных средств в неделю?',
            'Какой лимит подотчётных средств в первом квартале?',
            'Какой лимит подотчётных средств во 2 квартале?',
            'Покажи лимит подотчётных средств и замени его на 10000',
            'Какие настройки подотчётных средств и мои непрочитанные уведомления?',
            'Увеличь лимит выдачи подотчётных средств', 'Уменьши срок сдачи авансового отчёта',
            'Настройте лимит подотчётных средств', 'Настроить лимит подотчётных средств',
            'Снизь лимит выдачи подотчётных средств', 'Повысь лимит выдачи подотчётных средств',
            'Задай лимит выдачи подотчётных средств 10000', 'Выставь лимит подотчётных средств 10000',
            'Подправь лимит подотчётных средств',
            'Покажи настройки подотчётных средств и сделай лимит 10000',
            'Покажи настройки подотчётных средств и поставь лимит 10000',
            'Покажи настройки подотчётных средств и поставь новый лимит 10000',
            'Покажи лимит подотчётных средств и увеличь его до 10000',
            'Покажи настройки подотчётных средств и сделай нам новый максимальный лимит 10000',
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
