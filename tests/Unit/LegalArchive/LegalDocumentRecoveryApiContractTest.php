<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use PHPUnit\Framework\TestCase;

final class LegalDocumentRecoveryApiContractTest extends TestCase
{
    public function test_recovery_api_is_scoped_and_exposes_typed_phase_actions(): void
    {
        $routes = file_get_contents(__DIR__.'/../../../routes/api/v1/admin/legal_archive.php');
        $controller = file_get_contents(__DIR__.'/../../../app/Http/Controllers/Api/V1/Admin/LegalArchiveController.php');
        $registry = file_get_contents(__DIR__.'/../../../app/Services/LegalArchive/LegalArchiveRegistryService.php');
        $resource = file_get_contents(__DIR__.'/../../../app/Http/Resources/Api/V1/Admin/LegalArchive/LegalArchiveDocumentResource.php');
        $reporter = file_get_contents(__DIR__.'/../../../app/Services/LegalArchive/LegalDocumentCreateFailureReporter.php');

        self::assertIsString($routes);
        self::assertIsString($controller);
        self::assertIsString($registry);
        self::assertIsString($resource);
        self::assertIsString($reporter);
        self::assertStringContainsString("Route::get('document-recoveries'", $routes);
        self::assertStringContainsString("Route::post('document-recoveries/{operation}'", $routes);
        self::assertStringContainsString('recoveryIndex(', $controller);
        self::assertStringContainsString('recoverCreate(', $controller);
        self::assertStringContainsString("'operation_result' => 'in_progress'", $controller);
        self::assertStringContainsString("'retry_after' => \$retryAfter", $controller);
        self::assertStringContainsString("->where('created_by_user_id', \$actor->id)", $registry);
        self::assertStringContainsString("->where('source_create_status', '!=', 'completed')", $registry);
        self::assertStringContainsString("'operation_id' => \$this->create_operation_id", $resource);
        self::assertStringContainsString("'retry_action' => \$this->source_create_retry_action", $resource);
        self::assertStringContainsString("'retry_upload'", $controller);
        self::assertStringContainsString("'retry_finalize'", $registry);
        self::assertStringContainsString('throw new LegalDocumentCreateFailed($document->refresh(), $exception)', $registry);
        self::assertStringContainsString('LegalDocumentCreateFailureReporter', $controller);
        self::assertGreaterThanOrEqual(3, substr_count($controller, '$this->reportCreateFailure('));
        self::assertStringContainsString("'failure_fingerprint'", $reporter);
        self::assertStringContainsString("'correlation_id'", $reporter);
        self::assertStringNotContainsString("'message' => \$failure->getMessage()", $reporter);
        self::assertStringNotContainsString("'add_version'", $controller);
        self::assertStringNotContainsString("'repeat_create'", $controller);
    }

    public function test_postgres_contract_covers_create_recovery_races_and_replays(): void
    {
        $source = file_get_contents(__DIR__.'/../../Integration/LegalArchive/LegalDocumentCreateRecoveryPostgresIntegrationTest.php');
        self::assertIsString($source);

        foreach ([
            'test_two_stale_reclaim_workers_allow_one_claim_and_one_in_progress',
            'test_stale_attempt_token_cannot_finalize_or_fail_reclaimed_operation',
            'test_ready_version_selects_retry_finalize_without_inserting_new_version',
            'test_completed_lost_response_replay_does_not_mutate_document_or_versions',
            'test_audit_failure_rolls_back_then_best_effort_cas_preserves_recovery_state',
        ] as $test) {
            self::assertStringContainsString($test, $source);
        }
        self::assertStringContainsString('LEGAL_ARCHIVE_PG_CREATE_RECOVERY_CONTRACT', $source);
    }
}
