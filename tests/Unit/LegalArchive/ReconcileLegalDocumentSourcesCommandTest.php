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
        $definition = $command->getDefinition();

        self::assertTrue($definition->hasOption('organization'));
        self::assertTrue($definition->hasOption('source'));
        self::assertTrue($definition->hasOption('dry-run'));
        self::assertSame('100', $definition->getOption('limit')->getDefault());
    }
}
