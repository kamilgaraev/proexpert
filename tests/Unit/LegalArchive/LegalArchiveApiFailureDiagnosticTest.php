<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Http\Controllers\Api\V1\Admin\LegalArchive\LegalArchiveApiController;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LegalArchiveApiFailureDiagnosticTest extends TestCase
{
    public function test_it_extracts_only_safe_database_diagnostics_from_a_workflow_failure(): void
    {
        $controller = new class extends LegalArchiveApiController {};
        $method = new \ReflectionMethod($controller, 'databaseDiagnostics');
        $databaseError = new PDOException(
            'ERROR: legal_workflow_step_transition_forbidden; confidential payload must not be logged',
        );
        $databaseError->errorInfo = [
            'P0001',
            7,
            'ERROR: legal_workflow_step_transition_forbidden; confidential payload must not be logged',
        ];
        $queryError = new QueryException(
            'pgsql',
            'update "legal_workflow_steps" set "status" = ?, "completed_at" = ?',
            ['approved', '2026-08-28 14:15:41'],
            $databaseError,
        );

        self::assertSame([
            'database_sqlstate' => 'P0001',
            'database_invariant' => 'legal_workflow_step_transition_forbidden',
            'database_query_table' => 'legal_workflow_steps',
        ], $method->invoke($controller, $queryError));
    }

    public function test_it_rejects_unbounded_database_messages_and_identifiers(): void
    {
        $controller = new class extends LegalArchiveApiController {};
        $method = new \ReflectionMethod($controller, 'databaseDiagnostics');
        $databaseError = new PDOException('secret customer contract contents');
        $databaseError->errorInfo = ['not-a-sqlstate', 7, 'secret customer contract contents'];
        $queryError = new QueryException(
            'pgsql',
            'update "MixedCaseTable" set "payload" = ?',
            ['secret'],
            $databaseError,
        );

        self::assertSame([], $method->invoke($controller, $queryError));
        self::assertSame([], $method->invoke($controller, new RuntimeException('secret')));
    }
}
