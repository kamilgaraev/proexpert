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
    use UsesAssistantUnitTranslations;

    public function test_subscription_capabilities_use_catalog_instead_of_personal_schedules_or_project_search(): void
    {
        foreach ([
            'На какие отчёты я могу настроить автоматическую подписку и какие форматы доступны? Посмотри именно поддержку подписок, а не мои существующие расписания.',
            'Какие отчёты поддерживают автоматические подписки?',
            'Доступна ли автоматическая подписка на отчёты нашей организации?',
            'Можно ли получать каждую неделю отчёт об оплатах в Excel? Нужно понять, поддерживается ли такая автоматическая рассылка.',
            'Можно ли получать каждую неделю отчёт по платежам в Excel?',
            'Какие отчёты об оплатах поддерживают автоматические подписки нашей организации?',
        ] as $query) {
            $plan = ['request' => ['message' => $query, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]];
            self::assertSame(['name' => 'get_report_subscription_catalog', 'arguments' => ['scope' => 'organization']], AssistantRequiredProjectRead::fromPlan($plan), $query);
            self::assertContains('get_report_subscription_catalog', AssistantToolSurface::names(['get_report_subscription_catalog'], $plan));
            $understanding = (new \App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver)->resolve($query, []);
            $eligibility = new \App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantToolEligibilityPolicy;
            self::assertTrue($eligibility->isReadOnlyTool('get_report_subscription_catalog'));
            self::assertTrue($eligibility->canExposeTool('get_report_subscription_catalog', $understanding, false)->allowed);
            self::assertTrue($eligibility->canExecuteTool('get_report_subscription_catalog', $understanding, false, ['scope' => 'organization'])->allowed);
        }
        $personal = 'Покажи мои подписки на отчёты и доступные форматы рассылки.';
        self::assertFalse(\App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantReportSubscriptionCatalogReader::matches($personal));
        self::assertNull(AssistantOperationalReaderRegistry::intent($personal));
    }

    public function test_current_own_roles_with_temporary_assignments_do_not_select_assignable_roles(): void
    {
        $query = 'Какие роли у меня сейчас в этой организации? Есть ли временные назначения и когда они заканчиваются?';
        self::assertSame('get_own_organization_roles', \App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOperationalReaderRegistry::intent($query)['name']);
        self::assertFalse(\App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOwnRolesReader::matches('Какие роли у меня и какие роли могу ли назначить сотруднику?'));
    }

    public function test_natural_governance_questions_keep_organization_scope_despite_selected_project(): void
    {
        foreach ([
            ['Сколько единиц МОСТ сейчас доступно нашей организации для помощника? Покажи остаток и подскажи, где руководителю смотреть расход.', 'get_organization_credit_balance'],
            ['Сколько пользователей есть в нашей организации, сколько из них активны и сколько отключены? Мне нужны общие количества, без телефонов и почты.', 'get_organization_user_counts'],
            ['Какие роли я сейчас могу назначать сотрудникам нашей организации? Могу ли я назначить администратора и где это делается?', 'get_assignable_user_roles'],
            ['Какие у меня сейчас права в админке нашей организации? Могу ли я управлять пользователями, договорами и счетами?', 'get_own_admin_permissions'],
            ['Какие у меня права на сметы, склад и отчёты в админке: могу только смотреть или также изменять и выгружать?', 'get_own_admin_permissions'],
            ['Могу ли я редактировать сметы и выгружать отчёты? Какие у меня права на склад?', 'get_own_admin_permissions'],
            ['Какие у меня права на изменение настроек организации и уведомлений?', 'get_own_admin_permissions'],
            ['Какие пакеты МОСТ сейчас действуют у нашей организации и до каких дат оплачен доступ? Что мне нужно проверить перед окончанием периода?', 'get_organization_active_packages'],
            ['Какие у нашей организации сохранены адрес, телефон и электронная почта? Где руководителю проверить эти контакты перед отправкой документов?', 'get_organization_contacts'],
            ['Какие уведомления я получаю и по каким каналам? Что у меня включено для почты и уведомлений в приложении?', 'get_own_notification_preferences'],
            ['В нашей организации уведомления на почту включены? Какие настройки уведомлений у меня?', 'get_own_notification_preferences'],
            ['Какие уведомления у меня обязательные и их нельзя отключить, а какие можно настроить самому?', 'get_own_notification_preferences'],
            ['По каким событиям у меня уведомления приходят на электронную почту, а по каким только в приложении?', 'get_own_notification_preferences'],
            ['Насколько заполнен профиль нашей организации? Какие основные настройки и сведения о деятельности уже указаны?', 'get_organization_business_profile'],
            ['Какие отчёты у меня сохранены в избранном и какие я открывал недавно? Хочу быстро вернуться к нужному отчёту.', 'get_own_report_workspace'],
            ['Есть ли у нас настроенные регулярные отчёты? Какие активны, когда следующий запуск и в каком формате они приходят?', 'get_own_report_subscriptions'],
            ['Какие подписки на отчёты у меня сейчас настроены в нашей организации?', 'get_own_report_subscriptions'],
            ['Подтверждена ли наша организация? Какой сейчас статус проверки и что руководителю ещё нужно заполнить?', 'get_organization_verification'],
            ['Какая у меня сейчас роль в нашей организации? До какой даты действует мой доступ?', 'get_own_organization_roles'],
            ['Какие модули сейчас включены у нашей организации? Покажи действующие возможности, чтобы понимать, чем команда может пользоваться.', 'get_organization_effective_modules'],
            ['Какие функциональные модули сейчас реально доступны нашей организации? Перечисли разделы, без платёжных подробностей.', 'get_organization_effective_modules'],
            ['Что я могу делать с пользователями и договорами: только просматривать или также создавать и менять?', 'get_own_admin_permissions'],
        ] as [$query, $name]) {
            $plan = ['request' => ['message' => $query, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]];
            self::assertSame(['name' => $name, 'arguments' => ['scope' => 'organization']], AssistantRequiredProjectRead::fromPlan($plan), $query);
            self::assertContains($name, AssistantToolSurface::names([$name], $plan));
            self::assertFalse(AssistantProjectStaffReader::matches($query));
        }
    }

    public function test_saved_views_and_favourites_share_the_native_personal_workspace_reader(): void
    {
        foreach ([
            'Какие у меня сохранены представления отчётов текущей организации? Есть ли среди них устаревшие, и где я могу их открыть?',
            'Какие отчёты у меня в избранном, а какие сохранены отдельными представлениями? Это один и тот же список или разные?',
        ] as $query) {
            $plan = ['request' => ['message' => $query, 'context' => []]];
            self::assertSame(['name' => 'get_own_report_workspace', 'arguments' => ['scope' => 'organization']], AssistantRequiredProjectRead::fromPlan($plan));
        }
    }

    public function test_mixed_domains_and_writes_do_not_receive_partial_governance_replacement(): void
    {
        foreach ([
            'Сколько единиц МОСТ осталось для помощника и какие счета оплатить?',
            'Сколько пользователей организации и кто в отпуске?',
            'Какие роли могу назначать сотрудникам и какие договоры просрочены?',
            'Какие у меня права в админке и сколько просроченных счетов?',
            'Измени телефон нашей организации',
            'Какие контакты организации и кто в отпуске?',
            'Отключи уведомления по почте',
            'Отключите уведомления по почте в моих настройках',
            'Измените каналы моих уведомлений',
            'Отключить уведомления по почте',
            'Настроить мои уведомления',
            'Какие каналы уведомлений включены и покажи непрочитанные сообщения?',
            'Пополни баланс единиц МОСТ для помощника',
            'Назначь сотруднику роль администратора',
            'Какие у меня права в админке и открытые заказы поставщикам?',
            'Какие роли могу назначать сотрудникам и какие у меня уведомления?',
            'Какие у меня права в админке и покажи расходы организации?',
            'Какие у меня права на уведомления и покажи непрочитанные сообщения?',
            'Какие у меня права на отчёты и покажи готовую выгрузку?',
            'Какие пакеты МОСТ сейчас действуют и покажи расходы организации?',
            'Какие функциональные модули доступны без платёжных подробностей и сколько расходов у нашей организации?',
            'Что я могу делать с пользователями и договорами и сколько просроченных счетов?',
            'Какие отчёты поддерживают автоматические подписки и какие мои подписки уже активны?',
            'Какие пакеты МОСТ дают доступ и какие отчёты поддерживают подписки?',
            'Создай автоматическую подписку на доступные отчёты',
            'Какие отчёты об оплатах поддерживают подписки и сколько оплат было вчера?',
            'Можно ли получать отчёт об оплатах каждую неделю и какие счета оплатить?',
            'Какие отчёты поддерживают подписки и сколько стоит подписка на МОСТ?',
            'Можно ли получать отчёт об оплатах каждую неделю и какова задолженность организации?',
            'Какие отчёты об оплатах поддерживают подписки и какова общая сумма?',
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
