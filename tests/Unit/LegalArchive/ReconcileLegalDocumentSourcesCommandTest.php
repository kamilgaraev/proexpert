<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Console\Commands\LegalArchive\ReconcileLegalDocumentSourcesCommand;
use PHPUnit\Framework\TestCase;

final class ReconcileLegalDocumentSourcesCommandTest extends TestCase
{
    public function test_command_declares_safe_reconciliation_options(): void
    {
        $command = new ReconcileLegalDocumentSourcesCommand;
        self::assertStringContainsString('{--organization=}', (string) $command->getDefinition());
        self::assertStringContainsString('{--source=}', (string) $command->getDefinition());
        self::assertStringContainsString('{--dry-run}', (string) $command->getDefinition());
        self::assertStringContainsString('{--limit=100}', (string) $command->getDefinition());
    }
}
