<?php

declare(strict_types=1);

namespace Tests\Feature\LegalArchive;

use PHPUnit\Framework\TestCase;

final class ContractLegalDocumentEditorAccessTest extends TestCase
{
    public function test_contract_editor_route_requires_contract_and_editor_permissions(): void
    {
        $routes = file_get_contents(__DIR__.'/../../../routes/api/v1/admin/project-based.php');

        self::assertIsString($routes);
        self::assertStringContainsString("Route::post('/{contract}/documents/{legalDocument}/versions/{documentVersion}/editor/session'", $routes);
        self::assertStringContainsString("'authorize:contracts.edit,project,project', 'authorize:legal_archive.editor.edit'", $routes);
    }

    public function test_contract_editor_session_rejects_versions_outside_the_linked_contract_document(): void
    {
        $controller = file_get_contents(__DIR__.'/../../../app/Http/Controllers/Api/V1/Admin/LegalArchive/LegalArchiveFileController.php');

        self::assertIsString($controller);
        self::assertStringContainsString('public function contractEditorSession(', $controller);
        self::assertStringContainsString('(int) $found->document_id !== (int) $legalDocument', $controller);
        self::assertStringContainsString("'contract_editor_session'", $controller);
    }

    public function test_editor_eligibility_matches_the_server_edit_guard(): void
    {
        $resource = file_get_contents(__DIR__.'/../../../app/Http/Resources/Api/V1/Admin/LegalArchive/LegalArchiveDocumentVersionResource.php');

        self::assertIsString($resource);
        self::assertStringContainsString('(bool) $this->is_current', $resource);
        self::assertStringContainsString('$this->status === \'uploaded\'', $resource);
        self::assertStringContainsString('preg_match(\'/^[a-f0-9]{64}$/D\', (string) $this->content_hash) === 1', $resource);
    }

    public function test_contract_detail_exposes_editor_availability_from_the_domain_guard(): void
    {
        $controller = file_get_contents(__DIR__.'/../../../app/Http/Controllers/Api/V1/Admin/LegalArchive/LegalArchiveDocumentController.php');
        $resource = file_get_contents(__DIR__.'/../../../app/Http/Resources/Api/V1/Admin/LegalArchive/LegalArchiveDocumentResource.php');

        self::assertIsString($controller);
        self::assertIsString($resource);
        self::assertStringContainsString('LegalDocumentEditorAvailability', $controller);
        self::assertStringContainsString('api_editor_current_version_editable', $controller);
        self::assertStringContainsString("'current_version_editable'", $resource);
    }
}
