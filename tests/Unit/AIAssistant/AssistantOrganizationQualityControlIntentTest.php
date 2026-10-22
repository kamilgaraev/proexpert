<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantRequiredProjectRead;
use App\BusinessModules\Features\AIAssistant\Services\AssistantToolSurface;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOperationalReaderRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssistantOrganizationQualityControlIntentTest extends TestCase
{
    public static function supportedQuestions(): array
    {
        return [
            ['Покажи по всей организации незакрытые замечания по качеству, у которых срок устранения раньше сегодняшнего дня. Нужны ID, проект, название, статус, критичность и срок устранения. Отдельно отметь критические замечания. Черновики, устранённые и отменённые исключи. Не ограничивайся выбранным проектом.'],
            ['Какие просроченные дефекты качества есть по всей организации? Выдели критические.'],
            ['Дай список незакрытых просроченных замечаний качества по всем доступным проектам, кроме черновиков.'],
            ['Покажи просроченные дефекты качества по всем нашим проектам. Нужны ID и название проекта.'],
            ['Покажи незакрытые замечания по качеству со сроком до сегодняшнего дня по всей организации.'],
            ['Покажи только просроченные замечания по качеству по всей организации.'],
            ['Покажи лишь просроченные дефекты качества по всем доступным проектам.'],
            ['Покажи исключительно незакрытые просроченные замечания качества всей организации.'],
            ['Покажи просроченные замечания качества по всей организации. Отдельно покажи критические замечания.'],
            ['Нужны просроченные замечания качества по всей организации.'],
            ['Нужна сводка просроченных замечаний качества по всей организации.'],
        ];
    }

    #[DataProvider('supportedQuestions')]
    public function test_overdue_quality_control_uses_a_dedicated_organization_read(string $question): void
    {
        $intent = AssistantOperationalReaderRegistry::intent($question);
        self::assertSame('get_organization_quality_defect_control', $intent['name'] ?? null);
        $plan = ['request' => ['message' => $question,
            'context' => ['entity_refs' => [['type' => 'project', 'id' => 104]]]]];
        self::assertSame(['name' => 'get_organization_quality_defect_control', 'arguments' => ['scope' => 'organization']], AssistantRequiredProjectRead::fromPlan($plan));
        self::assertSame(['scope' => 'organization'], AssistantRequiredProjectRead::bindArguments($plan, 'get_organization_quality_defect_control', ['scope' => 'project', 'project_id' => 104]));
        self::assertContains('get_organization_quality_defect_control', AssistantToolSurface::names(['get_organization_quality_defect_control'], $plan));
        self::assertSame(AssistantOperationalReaderRegistry::ORGANIZATION_TOOLS['get_organization_quality_defect_control'], AssistantRequiredProjectRead::decisionReader('get_organization_quality_defect_control', $question));
    }

    public static function unsupportedQuestions(): array
    {
        return [
            ['Собери Excel по просроченным дефектам качества всей организации.'],
            ['Выгрузи CSV с просроченными замечаниями качества по всей организации.'],
            ['Сформируй PDF по просроченным замечаниям качества всей организации.'],
            ['Закрой просроченные дефекты качества по всей организации.'],
            ['Покажи просроченные дефекты качества проекта 102.'],
            ['Покажи просроченные дефекты качества всей организации, только проект 102.'],
            ['Покажи просроченные дефекты качества всей организации, кроме проекта 52.'],
            ['Покажи только критические просроченные дефекты качества всей организации.'],
            ['Покажи просроченные дефекты качества всей организации на вчера.'],
            ['Покажи дефекты качества всей организации со сроком до 01.10.2026.'],
            ['Покажи просроченные дефекты качества всей организации за 2025 год.'],
            ['Покажи просроченные дефекты качества всей организации. Черновики тоже учти.'],
            ['Покажи просроченные дефекты качества всей организации и стоимость устранения.'],
            ['Покажи незакрытые замечания качества всей организации без срока.'],
            ['Покажи просроченные задачи графика всей организации.'],
            ['Покажи существенные просроченные замечания качества всей организации.'],
            ['Покажи незначительные просроченные замечания качества всей организации.'],
            ['Покажи просроченные замечания качества всей организации, назначенные Иванову.'],
            ['Покажи просроченные замечания качества всей организации без ответственного.'],
            ['Покажи просроченные замечания качества всей организации в работе.'],
            ['Покажи просроченные замечания качества всей организации в статусе «открыт».'],
            ['Покажи непросроченные замечания качества всей организации.'],
            ['Покажи не просроченные замечания качества всей организации.'],
            ['Покажи замечания качества всей организации со сроком не раньше сегодняшнего дня.'],
            ['Отмени просроченные замечания качества всей организации.'],
            ['Покажи только просроченные открытые замечания качества всей организации.'],
            ['Покажи открытые просроченные замечания качества всей организации.'],
            'critical_subset' => ['Покажи критические замечания качества, просроченные по всей организации.'],
            'unsupported_columns' => ['Покажи просроченные замечания качества по всей организации. Нужны ID, описание и ответственный.'],
            ['Покажи просроченные замечания качества всей организации. Нужны ID, номер и дата создания.'],
            ['Покажи просроченные замечания качества всей организации. Нужны автор и дата устранения.'],
        ];
    }

    #[DataProvider('unsupportedQuestions')]
    public function test_other_scopes_conditions_and_actions_are_not_replaced(string $question): void
    {
        self::assertNotSame('get_organization_quality_defect_control', AssistantOperationalReaderRegistry::intent($question)['name'] ?? null);
    }
}
