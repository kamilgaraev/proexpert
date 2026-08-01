<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Retention;

use App\BusinessModules\Core\Reporting\Infrastructure\Console\DeleteExpiredReportArtifactsCommand;
use App\BusinessModules\Core\Reporting\Infrastructure\Console\DeliverReportAuditIntentsCommand;
use App\BusinessModules\Core\Reporting\Infrastructure\Console\ExpireReportsCommand;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ReportRetentionLocalizationTest extends TestCase
{
    public function test_console_descriptions_use_translated_report_keys(): void
    {
        $sources = [
            DeleteExpiredReportArtifactsCommand::class => 'reports.commands.delete_expired_artifacts',
            DeliverReportAuditIntentsCommand::class => 'reports.commands.deliver_audit_intents',
            ExpireReportsCommand::class => 'reports.commands.expire',
        ];

        foreach ($sources as $class => $key) {
            $source = (string) file_get_contents((new ReflectionClass($class))->getFileName());
            self::assertStringContainsString("trans_message('{$key}')", $source);
        }

        $translations = require dirname(__DIR__, 4).'/lang/ru/reports.php';
        self::assertSame('Передаёт ожидающие события отчётов в журнал аудита', $translations['commands']['deliver_audit_intents']);
        self::assertSame('Завершает срок хранения готовых отчётов и экспортов', $translations['commands']['expire']);
        self::assertSame('Удаляет версии файлов отчётов после окончания периода хранения', $translations['commands']['delete_expired_artifacts']);
    }
}
