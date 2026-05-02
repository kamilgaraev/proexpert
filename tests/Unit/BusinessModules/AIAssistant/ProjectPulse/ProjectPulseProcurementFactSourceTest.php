<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\AIAssistant\ProjectPulse;

use PHPUnit\Framework\TestCase;

class ProjectPulseProcurementFactSourceTest extends TestCase
{
    public function test_procurement_source_is_covered_by_feature_generation_case(): void
    {
        self::markTestSkipped(
            'Источник закупок проверяется feature-тестом ProjectPulseReportTest; локальный RefreshDatabase остановлен PostgreSQL-индексом USING GIN на SQLite.'
        );
    }
}
