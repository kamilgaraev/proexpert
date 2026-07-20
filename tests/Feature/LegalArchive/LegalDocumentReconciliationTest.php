<?php

declare(strict_types=1);

namespace Tests\Feature\LegalArchive;

use PHPUnit\Framework\TestCase;

final class LegalDocumentReconciliationTest extends TestCase
{
    public function test_reconciliation_contract_declares_dry_run_idempotency_and_global_limit_invariants(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/app/Services/LegalArchive/Integrations/LegalDocumentReconciliationService.php');
        self::assertStringContainsString('if (! $dryRun)', $source);
        self::assertStringContainsString("'source_idempotency_key'", $source);
        self::assertStringContainsString("if (\$summary['candidates'] >= \$limit)", $source);
        self::assertStringContainsString('limit($limit - $summary', $source);
    }
}
