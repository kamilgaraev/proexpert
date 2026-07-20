<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use PHPUnit\Framework\TestCase;

final class LegalDocumentManagementRecoveryApiContractTest extends TestCase
{
    public function test_break_glass_recovery_has_a_narrow_tenant_scoped_admin_contract(): void
    {
        $root = __DIR__.'/../../../';
        $route = file_get_contents($root.'routes/api/v1/admin/legal_archive.php');
        $request = file_get_contents(
            $root.'app/Http/Requests/Api/V1/Admin/LegalArchive/RecoverLegalDocumentManagementRequest.php',
        );
        $controller = file_get_contents(
            $root.'app/Http/Controllers/Api/V1/Admin/LegalArchiveController.php',
        );

        self::assertIsString($route);
        self::assertIsString($request);
        self::assertIsString($controller);
        self::assertStringContainsString('documents/{document}/management-recovery', $route);
        self::assertStringContainsString(
            "middleware('authorize:legal_archive.security_recovery.manage')",
            $route,
        );
        self::assertStringContainsString("'successor_user_id' => ['required', 'integer', 'min:1']", $request);
        self::assertStringContainsString("'legal_archive.security_recovery.manage'", $request);
        self::assertStringContainsString('findForOrganization(', $controller);
        self::assertStringContainsString('recoverManagementAsSecurityAdministrator(', $controller);
        self::assertStringContainsString('LegalArchiveDocumentResource($found->refresh())', $controller);
        self::assertStringContainsString("'successor_user_id' => (int) \$grant->subject_user_id", $controller);
    }
}
