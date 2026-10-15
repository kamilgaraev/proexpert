<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectExportHistoryReader;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class AssistantExportHistoryFormattingTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_ready_files_past_retention_are_counted_as_expired_and_partial_history_is_disclosed(): void
    {
        $reader = (new ReflectionClass(AssistantProjectExportHistoryReader::class))->newInstanceWithoutConstructor();
        $format = new ReflectionMethod(AssistantProjectExportHistoryReader::class, 'format');
        $items = [];
        foreach ([['ready', false], ['ready', true], ['expired', true], ['queued', false], ['failed', false], ['cancelled', true]] as [$status, $expired]) {
            $items[] = ['title' => 'Выгрузка '.$status, 'format' => 'csv', 'status' => $status,
                'retention_expired' => $expired, 'created_at' => '2026-10-15T12:00:00+00:00'];
        }
        $answer = $format->invoke($reader, ['project' => ['name' => 'Объект'], 'as_of_date' => '2026-10-15',
            'items' => $items, 'complete' => false]);

        self::assertStringContainsString('выборке: 6; готовы: 1;', $answer);
        self::assertStringContainsString('обработке: 1; с ошибкой: 1; отменены: 1; срок хранения истёк: 2.', $answer);
        self::assertStringContainsString(trans_message('ai_assistant_operational.export_history_partial'), $answer);
        self::assertStringContainsString('Ссылки не создавались, выгрузки не запускались.', $answer);
    }
}
