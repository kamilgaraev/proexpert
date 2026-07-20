<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use PHPUnit\Framework\TestCase;

final class ProcurementContractConcurrencyTest extends TestCase
{
    public function test_competing_purchase_order_creation_is_serialized_and_replayed_by_source_identity(): void
    {
        $root = dirname(__DIR__, 5).DIRECTORY_SEPARATOR;
        $service = file_get_contents($root.'app/BusinessModules/Features/Procurement/Services/PurchaseContractService.php');
        $orchestrator = file_get_contents($root.'app/Services/Contract/ContractDossierCreationService.php');
        $migration = file_get_contents($root.'database/migrations/2026_07_19_000800_enforce_contract_source_uniqueness.php');

        self::assertIsString($service);
        self::assertIsString($orchestrator);
        self::assertIsString($migration);

        $method = $this->methodSource($service, 'createFromOrder');
        self::assertStringContainsString('DB::transaction', $method);
        self::assertStringContainsString('->lockForUpdate()', $method);
        self::assertStringContainsString('if ($order->contract_id !== null)', $method);
        self::assertStringContainsString("idempotencyKey: 'purchase-order:'.\$order->id", $method);
        self::assertStringContainsString("sourceType: 'purchase_order'", $method);

        self::assertStringContainsString("where('source_type', \$input->sourceType)", $orchestrator);
        self::assertStringContainsString("where('source_id', \$input->sourceId)", $orchestrator);
        self::assertStringContainsString('return new ContractDossierCreationResult(', $orchestrator);
        self::assertStringContainsString("contract_dossier_sources_source_unique", $migration);
    }

    private function methodSource(string $source, string $method): string
    {
        $start = strpos($source, "function {$method}(");
        self::assertNotFalse($start, "Method {$method} was not found.");
        $next = strpos($source, "\n    public function ", $start + 1);
        $next = $next === false ? strpos($source, "\n    private function ", $start + 1) : $next;

        return substr($source, $start, $next === false ? null : $next - $start);
    }
}
