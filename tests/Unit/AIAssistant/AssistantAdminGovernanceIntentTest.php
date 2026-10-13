<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantRequiredProjectRead;
use App\BusinessModules\Features\AIAssistant\Services\AssistantToolSurface;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOperationalReaderRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectStaffReader;
use PHPUnit\Framework\TestCase;

final class AssistantAdminGovernanceIntentTest extends TestCase
{
    public function test_natural_governance_questions_keep_organization_scope_despite_selected_project(): void
    {
        foreach ([
            ['Сколько единиц МОСТ сейчас доступно нашей организации для помощника? Покажи остаток и подскажи, где руководителю смотреть расход.', 'get_organization_credit_balance'],
            ['Сколько пользователей есть в нашей организации, сколько из них активны и сколько отключены? Мне нужны общие количества, без телефонов и почты.', 'get_organization_user_counts'],
            ['Какие роли я сейчас могу назначать сотрудникам нашей организации? Могу ли я назначить администратора и где это делается?', 'get_assignable_user_roles'],
            ['Какие у меня сейчас права в админке нашей организации? Могу ли я управлять пользователями, договорами и счетами?', 'get_own_admin_permissions'],
            ['Какие пакеты МОСТ сейчас действуют у нашей организации и до каких дат оплачен доступ? Что мне нужно проверить перед окончанием периода?', 'get_organization_active_packages'],
            ['Какие модули сейчас включены у нашей организации? Покажи действующие возможности, чтобы понимать, чем команда может пользоваться.', 'get_organization_effective_modules'],
        ] as [$query, $name]) {
            $plan = ['request' => ['message' => $query, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]];
            self::assertSame(['name' => $name, 'arguments' => ['scope' => 'organization']], AssistantRequiredProjectRead::fromPlan($plan), $query);
            self::assertContains($name, AssistantToolSurface::names([$name], $plan));
            self::assertFalse(AssistantProjectStaffReader::matches($query));
        }
    }

    public function test_mixed_domains_and_writes_do_not_receive_partial_governance_replacement(): void
    {
        foreach ([
            'Сколько единиц МОСТ осталось для помощника и какие счета оплатить?',
            'Сколько пользователей организации и кто в отпуске?',
            'Какие роли могу назначать сотрудникам и какие договоры просрочены?',
            'Какие у меня права в админке и сколько просроченных счетов?',
            'Пополни баланс единиц МОСТ для помощника',
            'Назначь сотруднику роль администратора',
            'Какие у меня права в админке и открытые заказы поставщикам?',
            'Какие роли могу назначать сотрудникам и какие у меня уведомления?',
            'Какие у меня права в админке и покажи расходы организации?',
            'Какие пакеты МОСТ сейчас действуют и покажи расходы организации?',
            'Сколько единиц МОСТ доступно для помощника и покажи расходы организации?',
        ] as $query) {
            self::assertNull(AssistantOperationalReaderRegistry::intent($query), $query);
            $plan = ['request' => ['message' => $query, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]];
            self::assertNull(AssistantRequiredProjectRead::fromPlan($plan), $query);
            foreach (['get_own_admin_permissions', 'get_organization_active_packages', 'get_organization_credit_balance'] as $name) {
                self::assertNull(AssistantRequiredProjectRead::decisionReader($name, $query), $query);
            }
        }
    }
}
