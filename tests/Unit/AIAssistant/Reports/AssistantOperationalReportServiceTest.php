<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Reports;

use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantOperationalReportService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AssistantOperationalReportServiceTest extends TestCase
{
    #[DataProvider('formattedMachineValuesProvider')]
    public function test_formats_machine_values_for_pdf(
        mixed $value,
        ?string $table,
        ?string $column,
        string $expected
    ): void {
        $method = new ReflectionMethod(AssistantOperationalReportService::class, 'formatValue');
        $method->setAccessible(true);

        $this->assertSame(
            $expected,
            $method->invoke(new AssistantOperationalReportService, $value, $table, $column)
        );
    }

    /**
     * @return array<string, array{0: mixed, 1: string|null, 2: string|null, 3: string}>
     */
    public static function formattedMachineValuesProvider(): array
    {
        return [
            'purchase request approved' => ['approved', 'purchase_requests', 'status', 'Одобрена'],
            'supplier request responded' => ['responded', 'supplier_requests', 'status', 'Ответ получен'],
            'proposal accepted' => ['accepted', 'supplier_proposals', 'status', 'Принято'],
            'priority high' => ['high', 'purchase_requests', 'priority', 'Высокий'],
            'date value' => ['2026-06-05 12:30:00', 'supplier_requests', 'created_at', '05.06.2026'],
            'money-like value' => [194900, 'supplier_proposals', 'total_amount', '194 900,00'],
        ];
    }
}
