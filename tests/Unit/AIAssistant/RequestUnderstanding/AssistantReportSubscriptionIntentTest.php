<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\RequestUnderstanding;

use App\BusinessModules\Features\AIAssistant\Services\AssistantActionProposalService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantCapabilityRegistry;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantMutationIntent;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantReportSubscriptionIntent;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantToolEligibilityPolicy;
use PHPUnit\Framework\TestCase;
use Tests\Unit\AIAssistant\UsesAssistantUnitTranslations;

final class AssistantReportSubscriptionIntentTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_recurring_report_requests_require_confirmation_and_keep_reports_domain(): void
    {
        foreach ([
            'Хочу каждую пятницу получать в уведомления Excel по принятым работам проекта Тестовый',
            'Создай подписку на отчёт по материалам',
            'Настрой подписку на еженедельный отчёт',
            'Прошу подпиши меня на отчёт по проекту',
            'Подпиши меня на отчёт о выполненных работах',
            'Прошу подпиши нас на еженедельный отчёт',
            'Пожалуйста, присылай мне отчёт каждую пятницу',
            'Присылай мне отчёт ежедневно',
            'Можешь настроить мне подписку на отчёт каждую пятницу?',
            'Можешь ли создать мне подписку на Excel?',
        ] as $query) {
            self::assertSame('create', AssistantReportSubscriptionIntent::type($query));
            self::assertSame('create', AssistantMutationIntent::type($query));
            self::assertTrue(AssistantMutationIntent::containsRequest($query));
            $understanding = (new AssistantRequestUnderstandingResolver)->resolve($query, ['source_module' => 'notifications']);
            self::assertSame('create', $understanding->primaryIntent);
            self::assertSame('requires_confirmation', $understanding->actionPolicy);
            self::assertContains('report_subscription', $understanding->requestedEntities);
            self::assertSame('reports', (new AssistantCapabilityRegistry)->match($query, ['source_module' => 'notifications'])['domain']);
            $policy = new AssistantToolEligibilityPolicy;
            self::assertFalse($policy->canExposeTool('create_report_subscription', $understanding, false)->allowed);
            self::assertTrue($policy->canExposeTool('create_report_subscription', $understanding, true)->allowed);
            self::assertFalse($policy->canExposeTool('update_report_subscription', $understanding, true)->allowed);
            self::assertFalse($policy->canExecuteTool('create_report_subscription', $understanding, true)->allowed);
            self::assertTrue((new AssistantActionProposalService)->supportsGoal('create_report_subscription', $query));
            self::assertFalse(\App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantReportSubscriptionsReader::matches($query));
            $plan = ['request' => ['message' => $query], 'request_understanding' => $understanding->toArray()];
            self::assertNull(\App\BusinessModules\Features\AIAssistant\Services\AssistantRequiredProjectRead::fromPlan($plan));
            $surface = \App\BusinessModules\Features\AIAssistant\Services\AssistantToolSurface::names(
                ['create_report_subscription', 'update_report_subscription', 'get_report_subscription_catalog', 'get_own_report_subscriptions', 'create_schedule_task'], $plan);
            self::assertContains('create_report_subscription', $surface);
            self::assertContains('get_report_subscription_catalog', $surface);
            self::assertNotContains('update_report_subscription', $surface);
            self::assertNotContains('create_schedule_task', $surface);
        }
    }

    public function test_pause_resume_and_schedule_changes_are_updates_of_existing_subscription(): void
    {
        foreach (['Приостанови мою подписку на отчёт по работам',
            'Возобнови подписку на отчёт по материалам',
            'Перенеси мою подписку на отчёт на понедельник',
            'Обнови расписание подписки на отчёт', 'Выключи мою подписку на отчёт',
            'Можешь приостановить мою подписку на отчёт?'] as $query) {
            self::assertSame('update', AssistantReportSubscriptionIntent::type($query));
            $understanding = (new AssistantRequestUnderstandingResolver)->resolve($query);
            self::assertSame('update', $understanding->primaryIntent);
            self::assertSame('requires_confirmation', $understanding->actionPolicy);
            self::assertTrue((new AssistantToolEligibilityPolicy)->canExposeTool('update_report_subscription', $understanding, true)->allowed);
            self::assertFalse((new AssistantToolEligibilityPolicy)->canExposeTool('create_report_subscription', $understanding, true)->allowed);
            self::assertTrue((new AssistantActionProposalService)->supportsGoal('update_report_subscription', $query));
            self::assertFalse(\App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantReportSubscriptionsReader::matches($query));
        }
    }

    public function test_questions_and_ordinary_files_do_not_create_subscriptions(): void
    {
        foreach (['Как настроить подписку на отчёт?', 'Можно ли получать Excel каждую пятницу?',
            'Хочу узнать, как получать отчёт каждую неделю', 'Покажи мои подписки на отчёты',
            'Покажи настройки уведомлений', 'Создай еженедельный отчёт в Excel',
            'Не создавай подписку на отчёт', 'Хочу каждую пятницу не получать отчёт',
            'Подпиши отчёт о выполненных работах', 'Подписать отчёт о выполненных работах',
            'Пожалуйста, подпиши отчёт об активных подписках'] as $query) {
            self::assertNull(AssistantReportSubscriptionIntent::type($query), $query);
            self::assertFalse((new AssistantActionProposalService)->supportsGoal('create_report_subscription', $query), $query);
            self::assertFalse((new AssistantActionProposalService)->supportsGoal('update_report_subscription', $query), $query);
        }
    }

    public function test_no_actions_constraint_still_blocks_a_subscription_proposal(): void
    {
        $query = 'Создай подписку на отчёт по работам, но ничего не меняй и без действий';
        $understanding = (new AssistantRequestUnderstandingResolver)->resolve($query);
        self::assertSame('read_only', $understanding->actionPolicy);
        self::assertFalse((new AssistantToolEligibilityPolicy)->canExposeTool('create_report_subscription', $understanding, true)->allowed);
        self::assertFalse((new AssistantActionProposalService)->supportsGoal('create_report_subscription', $query));
    }
}
