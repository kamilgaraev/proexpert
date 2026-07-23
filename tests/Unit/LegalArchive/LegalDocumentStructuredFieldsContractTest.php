<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use PHPUnit\Framework\TestCase;

final class LegalDocumentStructuredFieldsContractTest extends TestCase
{
    public function test_document_contract_exposes_profile_fields_for_workflow_validation(): void
    {
        $request = $this->source('app/Http/Requests/Api/V1/Admin/LegalArchive/UpdateLegalArchiveDocumentRequest.php');
        $resource = $this->source('app/Http/Resources/Api/V1/Admin/LegalArchive/LegalArchiveDocumentResource.php');
        $registry = $this->source('app/Services/LegalArchive/LegalArchiveRegistryService.php');

        self::assertStringContainsString("'structured_fields' => ['nullable', 'array']", $request);
        self::assertStringContainsString("'structured_fields' => \$this->structured_fields ?? []", $resource);
        self::assertStringContainsString("'electronic_signing_available' =>", $resource);
        self::assertStringContainsString("'type_profile_configured' => \$typeProfileConfigured", $resource);
        self::assertStringContainsString("'profile_assignment' => [", $resource);
        self::assertStringContainsString('$payload[\'structured_fields\'] = $this->profileValidator->validate(', $registry);
        self::assertStringContainsString('enforceRequired: false', $registry);
        self::assertStringContainsString('(array) ($currentDocument?->structured_fields ?? [])', $registry);
        self::assertStringContainsString('array_intersect_key((array) ($data[\'metadata\'] ?? []), $profile->schema)', $registry);
        self::assertStringContainsString('(new LegalDocumentEditGuard(DB::connection()))->assertVersionMutationAllowed($document)', $registry);
    }

    public function test_contract_detail_uses_the_same_workflow_and_obligation_data_as_document_detail(): void
    {
        $controller = $this->source('app/Http/Controllers/Api/V1/Admin/LegalArchive/LegalArchiveDocumentController.php');
        $registry = $this->source('app/Services/LegalArchive/LegalArchiveRegistryService.php');

        self::assertGreaterThanOrEqual(
            2,
            substr_count($controller, '$this->actions->forMany($actor, collect([$found]))'),
        );
        self::assertStringContainsString("'obligations',", $registry);
    }

    public function test_workflow_and_activation_enforce_profile_readiness_and_publish_obligations(): void
    {
        $workflowActions = $this->source('app/Services/LegalArchive/Workflow/LegalWorkflowActionResolver.php');
        $workflowService = $this->source('app/Services/LegalArchive/Workflow/LegalDocumentWorkflowService.php');
        $lifecycle = $this->source('app/Services/LegalArchive/LegalArchiveLifecycleService.php');

        self::assertStringContainsString('LegalDocumentWorkflowReadinessGuard', $workflowActions);
        self::assertStringContainsString('assertReady($lockedDocument)', $workflowService);
        self::assertStringContainsString('syncFromEffectiveDocument($locked)', $lifecycle);
    }

    public function test_electronic_signing_capability_fails_closed_for_unknown_driver(): void
    {
        $resource = $this->source('app/Http/Resources/Api/V1/Admin/LegalArchive/LegalArchiveDocumentResource.php');

        self::assertStringContainsString('ElectronicSignatureProvider::class', $resource);
        self::assertStringContainsString('is_a($providerClass, ElectronicSignatureProvider::class, true)', $resource);
    }

    public function test_linked_contract_document_requires_contract_edit_for_generic_update(): void
    {
        $controller = $this->source('app/Http/Controllers/Api/V1/Admin/LegalArchive/LegalArchiveDocumentController.php');

        self::assertStringContainsString('assertLinkedContractUpdateAllowed($actor, $found)', $controller);
        self::assertStringContainsString("'contracts.edit'", $controller);
        self::assertStringContainsString('withStatus(403)', $controller);
    }

    private function source(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 3).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath));

        self::assertIsString($source);

        return $source;
    }
}
