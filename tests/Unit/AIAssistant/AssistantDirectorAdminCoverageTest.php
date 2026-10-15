<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantRequiredProjectRead;
use App\BusinessModules\Features\AIAssistant\Services\AssistantToolSurface;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOperationalReaderRegistry;
use PHPUnit\Framework\TestCase;

final class AssistantDirectorAdminCoverageTest extends TestCase
{
    public function test_director_questions_bind_native_tool_and_scope_and_expose_the_tool(): void
    {
        $cases = [
            ['Какие заявки на материалы нашего объекта просрочены? Заявки на технику сюда не включай.', 'get_project_material_request_queue', 52],
            ['По договорам нашего объекта какие акты сейчас ждут согласования? Сколько таких актов и что проверить перед решением?', 'get_project_act_readiness', 52],
            ['Кто из сотрудников числится на нашем объекте сейчас? Сколько человек, как зовут и кто за что отвечает?', 'get_project_staff_roster', 52],
            ['Посмотри по всей нашей организации: какие тендеры ждут решения руководителя и где рискуем пропустить срок подачи? Сколько таких тендеров?', 'get_project_tender_decisions', null],
            ['Какие заявки с нашего объекта просрочены или остались без ответственного? Назови их, сроки и что нужно сделать руководителю.', 'get_project_site_request_queue', 52],
        ];
        foreach ($cases as [$query, $name, $projectId]) {
            $plan = ['request' => ['message' => $query, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]];
            self::assertSame(['name' => $name, 'arguments' => ['project_id' => $projectId]], AssistantRequiredProjectRead::fromPlan($plan), $query);
            self::assertSame(['project_id' => $projectId], AssistantRequiredProjectRead::bindArguments($plan, $name, ['project_id' => 999]));
            self::assertContains($name, AssistantToolSurface::names([$name], $plan));
            self::assertSame(AssistantOperationalReaderRegistry::TOOLS[$name], AssistantRequiredProjectRead::decisionReader($name, $query));
        }
    }

    public function test_mixed_questions_and_actions_do_not_get_a_single_domain_answer(): void
    {
        foreach (['Акты ждут согласования и какие заявки просрочены на объекте?', 'Кто из сотрудников на объекте и сколько им выплатить?',
            'Тендеры ждут решения и какие договоры подписать?', 'Создай заявку на объекте без ответственного',
            'Какие акты ждут согласования и сколько расходов за сентябрь?', 'Какие сотрудники назначены на завтра?',
            'Какие акты ждут согласования и какие действующие договоры по нашему объекту?',
            'Какие акты ждут согласования? А какие действующие договоры есть на нашем объекте?',
            'Какие сотрудники числятся на нашем объекте и какие задачи графика просрочены?'] as $query) {
            self::assertNull(AssistantOperationalReaderRegistry::intent($query), $query);
            $plan = ['request' => ['message' => $query, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]];
            self::assertNull(AssistantRequiredProjectRead::fromPlan($plan), $query);
            self::assertNull(AssistantRequiredProjectRead::decisionReader('get_project_staff_roster', $query), $query);
        }
    }

    public function test_active_projects_and_current_data_do_not_match_act_prefix(): void
    {
        foreach (['Какие активные проекты нашего объекта требуют решения?', 'Актуальные данные проекта требуют согласования?'] as $query) {
            self::assertNull(AssistantOperationalReaderRegistry::intent($query));
            self::assertFalse(AssistantOperationalReaderRegistry::hasIntent($query));
            self::assertSame([], AssistantToolSurface::names(['get_project_act_readiness'], ['request' => ['message' => $query]]));
        }
        foreach (['Какие акты ждут согласования?', 'Согласование актов нашего объекта', 'Что проверить перед одобрением акта?'] as $query) {
            self::assertSame('get_project_act_readiness', AssistantOperationalReaderRegistry::intent($query)['name'] ?? null);
        }
    }
}
