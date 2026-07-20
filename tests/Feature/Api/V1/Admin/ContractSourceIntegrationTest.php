<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use PHPUnit\Framework\TestCase;

final class ContractSourceIntegrationTest extends TestCase
{
    public function test_every_contract_source_uses_the_canonical_dossier_orchestrator(): void
    {
        $root = dirname(__DIR__, 5).DIRECTORY_SEPARATOR;
        $sources = [
            'app/Http/Controllers/Api/V1/Admin/ContractController.php' => [],
            'app/Http/Controllers/Api/V1/Admin/ContractFromEstimateController.php' => ["sourceType: 'estimate'", "sourceId: (string) \$estimate->id"],
            'app/BusinessModules/Features/Crm/Services/DealConversionWizardService.php' => ["sourceType: 'crm_deal'", "sourceId: (string) \$deal->id", "'preview_hash'"],
            'app/BusinessModules/Features/CommercialProposals/Http/Controllers/CommercialProposalController.php' => ["sourceType: 'commercial_proposal'", 'sourceId: $proposalId'],
            'app/BusinessModules/Features/Procurement/Services/PurchaseContractService.php' => ["sourceType: 'purchase_order'", "sourceId: (string) \$order->id"],
        ];

        foreach ($sources as $path => $requirements) {
            $source = file_get_contents($root.$path);
            self::assertIsString($source, $path);
            self::assertStringContainsString('ContractDossierCreation', $source, $path);

            foreach ($requirements as $requirement) {
                self::assertStringContainsString($requirement, $source, $path);
            }
        }
    }

    public function test_payment_recovery_exposes_continuation_and_blocks_inactive_contracts(): void
    {
        $root = dirname(__DIR__, 5).DIRECTORY_SEPARATOR;
        $source = file_get_contents($root.'app/BusinessModules/Core/Payments/Services/PurchaseOrderContractRequirementService.php');

        self::assertIsString($source);
        self::assertStringContainsString("'type' => 'continue_contract_creation'", $source);
        self::assertStringContainsString("'contract_required_not_active'", $source);
        self::assertStringContainsString('assertPaymentAllowed', $source);
    }

    public function test_estimate_endpoint_maps_domain_codes_to_translated_messages(): void
    {
        $root = dirname(__DIR__, 5).DIRECTORY_SEPARATOR;
        $controller = file_get_contents($root.'app/Http/Controllers/Api/V1/Admin/ContractFromEstimateController.php');
        $translations = file_get_contents($root.'lang/ru/contract.php');

        self::assertIsString($controller);
        self::assertIsString($translations);
        self::assertStringContainsString("trans_message('contract.estimate_context_invalid')", $controller);
        self::assertStringContainsString("trans_message('contract.estimate_items_invalid')", $controller);
        self::assertStringContainsString("'estimate_context_invalid' =>", $translations);
        self::assertStringContainsString("'estimate_items_invalid' =>", $translations);
    }
}
