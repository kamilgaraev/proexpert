<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantRequiredProjectRead;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOperationalReaderRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationCalculationHealthReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssistantCalculationHealthIntentTest extends TestCase
{
    #[DataProvider('dataRequests')]
    public function test_requesting_calculation_data_does_not_force_health_read(string $query): void
    {
        self::assertFalse(AssistantOrganizationCalculationHealthReader::hasIntent($query));
        self::assertFalse(AssistantOrganizationCalculationHealthReader::matches($query));
        self::assertNotSame('get_organization_calculation_health', AssistantOperationalReaderRegistry::intent($query)['name'] ?? null);
        self::assertNotSame('get_organization_calculation_health', AssistantRequiredProjectRead::fromPlan(['request' => ['message' => $query]])['name'] ?? null);
    }

    public static function dataRequests(): array
    {
        return [
            ['Покажи управленческие расчётные данные всей организации.'],
            ['Покажи расчетные данные всей организации в финансовом центре.'],
            ['Дай витрину управленческих расчётных данных всей организации.'],
            ['Покажи актуальные управленческие расчётные данные всей организации.'],
            ['Покажи подготовленные управленческие расчётные данные всей организации.'],
            ['Покажи успешные расчётные данные всей организации в бюджетировании.'],
        ];
    }

    #[DataProvider('healthRequests')]
    public function test_explicit_preparation_or_status_request_selects_health_read(string $query): void
    {
        self::assertTrue(AssistantOrganizationCalculationHealthReader::hasIntent($query));
        self::assertTrue(AssistantOrganizationCalculationHealthReader::matches($query));
        self::assertSame('get_organization_calculation_health', AssistantOperationalReaderRegistry::intent($query)['name'] ?? null);
        self::assertSame(['name' => 'get_organization_calculation_health', 'arguments' => ['scope' => 'organization']], AssistantRequiredProjectRead::fromPlan(['request' => ['message' => $query]]));
    }

    public static function healthRequests(): array
    {
        return [
            ['В каком состоянии управленческие расчётные данные всей организации?'],
            ['Проверь, запускалась ли подготовка расчётных данных в бюджетировании по всей организации. Ничего не пересчитывай.'],
            ['Проверь актуальность управленческих расчётных данных всей организации.'],
            ['Актуальны ли управленческие расчётные данные всей организации?'],
            ['Подготовлены ли управленческие расчётные данные всей организации?'],
            ['Были ли успешные расчёты управленческих данных всей организации?'],
            ['Почему отчёт о здоровье портфеля по всей нашей организации не строится? Проверь, запускалась ли подготовка расчётных данных в бюджетировании и были ли успешные расчёты. Ничего не пересчитывай. Объясни, что руководителю сделать дальше.'],
        ];
    }
}
