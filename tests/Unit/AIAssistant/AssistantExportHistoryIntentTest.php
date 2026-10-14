<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOperationalReaderRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectExportHistoryReader;
use PHPUnit\Framework\TestCase;

final class AssistantExportHistoryIntentTest extends TestCase
{
    public function test_manager_history_questions_select_a_read_only_source_and_mixed_actions_do_not(): void
    {
        foreach (['Какие готовые выгрузки отчётов нашего объекта у нас есть? Какие ещё обрабатываются и какие завершились ошибкой?',
            'Покажи историю выгрузок отчётов объекта', 'Какие выгрузки ещё в очереди?'] as $query) {
            self::assertSame('get_project_export_history', AssistantOperationalReaderRegistry::intent($query)['name'] ?? null, $query);
        }
        foreach (['Скачай готовую выгрузку', 'Покажи готовые выгрузки и расходы проекта',
            'Какие готовые выгрузки и какие у меня уведомления?', 'Какие у меня права на отчёты и покажи готовую выгрузку?'] as $query) {
            self::assertFalse(AssistantProjectExportHistoryReader::matches($query), $query);
            self::assertNull(AssistantOperationalReaderRegistry::intent($query), $query);
        }
    }
}
