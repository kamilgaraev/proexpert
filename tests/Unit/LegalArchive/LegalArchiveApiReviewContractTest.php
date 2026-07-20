<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use PHPUnit\Framework\TestCase;

final class LegalArchiveApiReviewContractTest extends TestCase
{
    public function test_signature_replays_precede_lock_conflicts_and_admin_actions_require_lock_version(): void
    {
        $service = file_get_contents(__DIR__.'/../../../app/Services/LegalArchive/Signatures/LegalDocumentSignatureService.php');
        $controller = file_get_contents(__DIR__.'/../../../app/Http/Controllers/Api/V1/Admin/LegalArchive/LegalArchiveSignatureController.php');
        self::assertIsString($service);
        self::assertIsString($controller);

        foreach (['public function createRequest(', 'private function registerOriginal('] as $method) {
            $section = substr($service, (int) strpos($service, $method), 12000);
            self::assertLessThan(strpos($section, 'LegalArchiveLockConflict::forDocument'), strpos($section, '$existing instanceof'));
            self::assertStringContainsString('assertSameRequest', $section);
        }
        $verify = substr($service, (int) strpos($service, 'public function verify('), 9000);
        self::assertLessThan(strpos($verify, 'describeVersion('), strpos($verify, '$replay instanceof LegalSignatureVerification'));
        self::assertStringContainsString('?int $expectedDocumentLockVersion', $verify);
        self::assertGreaterThanOrEqual(2, substr_count($controller, "'lock_version' => ['required', 'integer', 'min:0']"));
    }

    public function test_profile_template_type_and_forward_migration_are_consistent(): void
    {
        $profile = file_get_contents(__DIR__.'/../../../app/Services/LegalArchive/Profiles/LegalDocumentProfile.php');
        $model = file_get_contents(__DIR__.'/../../../app/BusinessModules/Features/LegalArchive/Models/LegalArchiveDocumentTypeProfile.php');
        $migration = file_get_contents(__DIR__.'/../../../database/migrations/2026_07_20_000700_fix_legal_profile_workflow_template_type.php');
        self::assertIsString($profile);
        self::assertIsString($model);
        self::assertIsString($migration);
        self::assertStringContainsString('public ?int $workflowTemplateId', $profile);
        self::assertStringContainsString("'workflow_template_id' => 'integer'", $model);
        self::assertStringContainsString('workflow_template_bigint_id BIGINT', $migration);
        self::assertStringContainsString('NOT VALID', $migration);
        self::assertStringContainsString('VALIDATE CONSTRAINT', $migration);
        self::assertStringContainsString('CREATE INDEX CONCURRENTLY IF NOT EXISTS', $migration);
        self::assertStringContainsString('legal_profile_workflow_template_uuid_reconciliation_required', $migration);
        self::assertStringContainsString('FOREIGN KEY (organization_id, code, template_id)', $migration);
        self::assertStringContainsString('FOREIGN KEY (organization_id, workflow_template_id)', $migration);
        self::assertStringContainsString('REFERENCES legal_workflow_template_heads (organization_id, template_id)', $migration);
        self::assertStringContainsString('DROP COLUMN workflow_template_legacy_uuid', $migration);
        self::assertStringNotContainsString('UPDATE legal_archive_document_type_profiles', $migration);
        self::assertStringNotContainsString('split_part', $migration);
    }

    public function test_workflow_settings_round_trip_every_step_field_and_lists_are_bounded(): void
    {
        $store = file_get_contents(__DIR__.'/../../../app/Http/Requests/Api/V1/Admin/LegalArchive/StoreLegalArchiveWorkflowTemplateRequest.php');
        $settings = file_get_contents(__DIR__.'/../../../app/Http/Controllers/Api/V1/Admin/LegalArchive/LegalArchiveSettingsController.php');
        $resource = file_get_contents(__DIR__.'/../../../app/Http/Resources/Api/V1/Admin/LegalArchive/LegalArchiveWorkflowTemplateResource.php');
        self::assertIsString($store);
        self::assertIsString($settings);
        self::assertIsString($resource);
        foreach (['parallel_group', 'policy_key', 'settings'] as $field) {
            self::assertStringContainsString("steps.*.{$field}", $store);
            self::assertStringContainsString("'{$field}'", $resource);
        }
        self::assertStringContainsString('paginate($perPage)', $settings);
        self::assertStringContainsString("boolean('all_versions')", $settings);
        self::assertStringContainsString('->limit($remaining)', $settings);
    }

    public function test_signature_view_permission_and_provider_callback_are_separate(): void
    {
        $routes = file_get_contents(__DIR__.'/../../../routes/api/v1/admin/legal_archive.php');
        $api = file_get_contents(__DIR__.'/../../../routes/api.php');
        $resource = file_get_contents(__DIR__.'/../../../app/Http/Resources/Api/V1/Admin/LegalArchive/LegalArchiveSignatureResource.php');
        self::assertIsString($routes);
        self::assertIsString($api);
        self::assertIsString($resource);
        self::assertStringContainsString('authorize:legal_archive.signatures.view', $routes);
        self::assertStringContainsString('legal-document-signatures/callback', $api);
        self::assertStringContainsString('legal-signature-callback', $api);
        self::assertStringContainsString('LegalSignatureCallbackBodyLimit::class', $api);
        self::assertStringNotContainsString('certificate_metadata', $resource);
        self::assertStringNotContainsString('provider_metadata', $resource);
    }

    public function test_mutation_controllers_emit_etag_and_refresh_location(): void
    {
        $root = __DIR__.'/../../../app/Http/Controllers/Api/V1/Admin/LegalArchive/';
        foreach (['LegalArchiveDocumentController.php', 'LegalArchiveFileController.php', 'LegalArchiveWorkflowController.php', 'LegalArchiveSignatureController.php', 'LegalArchiveAccessController.php', 'LegalArchiveRetentionController.php'] as $file) {
            $source = file_get_contents($root.$file);
            self::assertIsString($source);
            self::assertStringContainsString('$this->etag(', $source, $file);
        }
        $base = file_get_contents($root.'LegalArchiveApiController.php');
        self::assertIsString($base);
        self::assertStringContainsString("'Location' => \$error->refreshUrl", $base);
        self::assertStringContainsString("'refresh_url' => \$error->refreshUrl", $base);
    }
}
