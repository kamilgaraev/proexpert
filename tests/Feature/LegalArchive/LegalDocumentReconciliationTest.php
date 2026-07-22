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
        self::assertStringContainsString('->chunkById(100, function (Collection $entities)', $source);
        self::assertStringContainsString("if (\$summary['candidates'] >= \$limit)", $source);
        self::assertStringContainsString("->whereIn('source_id', \$entities->modelKeys())", $source);
    }

    public function test_created_legacy_contract_dossier_is_linked_in_the_same_reconciliation_pass(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/app/Services/LegalArchive/Integrations/LegalDocumentReconciliationService.php');
        $creation = strpos($source, '$this->createAndLinkContract($entity, $organizationId, $sourceType, $summary);');
        $repairBranch = strpos($source, '} elseif ($needsLinkRepair)', $creation ?: 0);

        self::assertIsInt($creation);
        self::assertIsInt($repairBranch);
        self::assertLessThan($repairBranch, $creation);
    }

    public function test_created_legacy_contract_dossier_is_linked_inside_a_locked_transaction(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/app/Services/LegalArchive/Integrations/LegalDocumentReconciliationService.php');

        self::assertStringContainsString('private function createAndLinkContract(', $source);
        self::assertStringContainsString('$this->connection->transaction(function () use (', $source);
        self::assertStringContainsString("->where('organization_id', \$organizationId)", $source);
        self::assertStringContainsString('->lockForUpdate()', $source);
        self::assertStringContainsString('$boundDocumentId > 0 && $boundDocumentId !== (int) $document->id', $source);
    }

    public function test_repair_does_not_replace_an_existing_contract_dossier_link(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/app/Services/LegalArchive/Integrations/LegalDocumentReconciliationService.php');
        $repair = substr($source, (int) strpos($source, 'private function repairContractLinks('), (int) strpos($source, 'private function unreconciled(') - (int) strpos($source, 'private function repairContractLinks('));

        self::assertStringContainsString("->whereNull('contracts.legal_archive_document_id')", $repair);
        self::assertStringNotContainsString("->orWhereColumn('contracts.legal_archive_document_id', '!=', 'dossier.id')", $repair);
        self::assertStringContainsString("\$this->createAndLinkContract(\$contract, (int) \$contract->organization_id, 'contract', \$summary);", $repair);
    }

    public function test_prebound_contract_is_skipped_before_creating_a_legacy_dossier(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/app/Services/LegalArchive/Integrations/LegalDocumentReconciliationService.php');
        $helper = substr($source, (int) strpos($source, 'private function createAndLinkContract('), (int) strpos($source, 'private function sourceQuery(') - (int) strpos($source, 'private function createAndLinkContract('));
        $preboundGuard = strpos($helper, 'if ((int) $lockedContract->legal_archive_document_id > 0)');
        $create = strpos($helper, '$this->documents->create(');

        self::assertIsInt($preboundGuard);
        self::assertIsInt($create);
        self::assertLessThan($create, $preboundGuard);
    }

    public function test_race_repair_uses_the_same_locked_contract_path(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/app/Services/LegalArchive/Integrations/LegalDocumentReconciliationService.php');
        $start = (int) strpos($source, '} elseif ($needsLinkRepair)');
        $repair = substr($source, $start, (int) strpos($source, '} else {', $start) - $start);

        self::assertStringContainsString('$this->createAndLinkContract($entity, $organizationId, $sourceType, $summary);', $repair);
        self::assertStringNotContainsString('$this->linkContract($entity, $document, $dryRun, $summary);', $repair);
    }

    public function test_reconciliation_of_legacy_sources_is_scheduled(): void
    {
        $schedule = (string) file_get_contents(dirname(__DIR__, 3).'/routes/console.php');

        self::assertMatchesRegularExpression(
            "/Schedule::command\('legal-archive:reconcile --limit=100'\)\s*->everyFiveMinutes\(\)\s*->withoutOverlapping\(60\)\s*->onOneServer\(\)\s*->runInBackground\(\);/",
            $schedule,
        );
    }
}
