<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use PHPUnit\Framework\TestCase;

final class LegalArchiveApiArchitectureTest extends TestCase
{
    public function test_api_is_split_by_domain_and_obsolete_monoliths_are_removed(): void
    {
        $root = __DIR__.'/../../../';

        foreach ([
            'LegalArchiveDocumentController',
            'LegalArchiveFileController',
            'LegalArchiveWorkflowController',
            'LegalArchiveSignatureController',
            'LegalArchiveAccessController',
            'LegalArchiveRetentionController',
            'LegalArchiveSettingsController',
        ] as $controller) {
            self::assertFileExists($root.'app/Http/Controllers/Api/V1/Admin/LegalArchive/'.$controller.'.php');
        }

        self::assertFileDoesNotExist($root.'app/Http/Controllers/Api/V1/Admin/LegalArchiveController.php');
        self::assertFileDoesNotExist($root.'app/Http/Controllers/Api/V1/Admin/LegalDocumentVersionAccessController.php');
    }

    public function test_route_map_has_canonical_domains_exact_permissions_and_no_duplicate_definitions(): void
    {
        $routes = file_get_contents(__DIR__.'/../../../routes/api/v1/admin/legal_archive.php');
        self::assertIsString($routes);

        foreach ([
            'documents/{legalDocument}/files',
            'document-files/{legalDocumentFile}/versions',
            'documents/{legalDocument}/workflow/submit',
            'signature-requests/{signatureRequest}/signing-session',
            'documents/{legalDocument}/access',
            'documents/{legalDocument}/retention',
            'documents/{legalDocument}/legal-hold',
            'documents/{legalDocument}/timeline',
            'type-profiles',
            'workflow-templates',
        ] as $uri) {
            self::assertStringContainsString($uri, $routes);
        }

        foreach ([
            'legal_archive.files.upload',
            'legal_archive.files.download',
            'legal_archive.versions.manage',
            'legal_archive.workflow.approve',
            'legal_archive.signatures.sign',
            'legal_archive.external_access.manage',
            'legal_archive.retention.manage',
            'legal_archive.legal_hold.manage',
            'legal_archive.audit.view',
            'legal_archive.settings.manage',
        ] as $permission) {
            self::assertStringContainsString('authorize:'.$permission, $routes);
        }

        preg_match_all("/Route::(get|post|patch|put|delete)\('([^']+)'/", $routes, $matches);
        $definitions = array_map(
            static fn (string $method, string $uri): string => $method.' '.$uri,
            $matches[1],
            $matches[2],
        );
        self::assertSame($definitions, array_values(array_unique($definitions)));
    }

    public function test_mutable_aggregate_requests_require_optimistic_lock_and_conflicts_are_stable(): void
    {
        $root = __DIR__.'/../../../';
        foreach ([
            'UpdateLegalArchiveDocumentRequest.php',
            'StoreLegalArchiveVersionRequest.php',
            'StoreLegalArchiveFileRequest.php',
            'StoreLegalArchiveFileVersionRequest.php',
            'UpdateLegalArchiveAccessRequest.php',
            'UpdateLegalArchiveRetentionRequest.php',
            'UpdateLegalArchiveLegalHoldRequest.php',
            'RecoverLegalDocumentManagementRequest.php',
        ] as $request) {
            $source = file_get_contents($root.'app/Http/Requests/Api/V1/Admin/LegalArchive/'.$request);
            self::assertIsString($source);
            self::assertStringContainsString("'lock_version' => ['required', 'integer', 'min:0']", $source, $request);
        }

        $controller = file_get_contents($root.'app/Http/Controllers/Api/V1/Admin/LegalArchive/LegalArchiveApiController.php');
        self::assertIsString($controller);
        self::assertStringContainsString('instanceof LegalArchiveLockConflict', $controller);
        self::assertStringContainsString("'current_lock_version' => \$error->currentLockVersion", $controller);
        self::assertStringContainsString("'refresh_url' => \$error->refreshUrl", $controller);
        self::assertStringContainsString('409', $controller);
    }

    public function test_detail_resource_and_transition_aliases_have_explicit_contracts(): void
    {
        $resource = file_get_contents(__DIR__.'/../../../app/Http/Resources/Api/V1/Admin/LegalArchive/LegalArchiveDocumentResource.php');
        $files = file_get_contents(__DIR__.'/../../../app/Http/Controllers/Api/V1/Admin/LegalArchive/LegalArchiveFileController.php');
        self::assertIsString($resource);
        self::assertIsString($files);

        foreach (['type_profile', 'current_primary_version', 'workflow_summary', 'problem_flags', 'linked_contract', 'lock_version'] as $field) {
            self::assertStringContainsString("'{$field}'", $resource);
        }
        self::assertSame(2, substr_count($files, "'Deprecation' => 'true'"));
        self::assertSame(2, substr_count($files, "'Sunset' => 'Wed, 31 Dec 2026 23:59:59 GMT'"));
    }

    public function test_list_uses_bounded_eager_loading_and_bulk_workflow_resolution(): void
    {
        $registry = file_get_contents(__DIR__.'/../../../app/Services/LegalArchive/LegalArchiveRegistryService.php');
        $controller = file_get_contents(__DIR__.'/../../../app/Http/Controllers/Api/V1/Admin/LegalArchive/LegalArchiveDocumentController.php');
        self::assertIsString($registry);
        self::assertIsString($controller);

        $paginate = substr($registry, (int) strpos($registry, 'public function paginate('), 1800);
        self::assertStringContainsString("'latestWorkflowInstance.steps'", $paginate);
        foreach (['files', 'signatureRequests', 'signatures', 'open_blocking_comments_count'] as $relation) {
            self::assertStringContainsString($relation, $paginate);
        }
        $index = substr($controller, (int) strpos($controller, 'public function index('), 1800);
        self::assertStringContainsString('foreach (', $index);
        self::assertStringContainsString('$this->actions->forMany(', $index);
        self::assertStringNotContainsString('$this->actions->for(', $index);
    }
}
