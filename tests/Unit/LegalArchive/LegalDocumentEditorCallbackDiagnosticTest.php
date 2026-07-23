<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Http\Controllers\Api\V1\Admin\LegalDocumentEditorController;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LegalDocumentEditorCallbackDiagnosticTest extends TestCase
{
    public function test_it_extracts_a_safe_database_guard_code_from_an_editor_callback_failure(): void
    {
        $controller = (new \ReflectionClass(LegalDocumentEditorController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($controller, 'diagnosticCode');
        $databaseError = new QueryException(
            'pgsql',
            'update legal_document_editor_saves set state = ?',
            [],
            new PDOException('ERROR: legal_document_editor_session_generation_unbacked'),
        );

        self::assertSame(
            'legal_document_editor_session_generation_unbacked',
            $method->invoke($controller, $databaseError),
        );
        $sqlStateError = new QueryException(
            'pgsql',
            'update legal_document_editor_saves set state = ?',
            [],
            new PDOException('ERROR: duplicate key', 23505),
        );

        self::assertSame('sqlstate_23505', $method->invoke($controller, $sqlStateError));
        $errorInfoState = new QueryException(
            'pgsql',
            'update legal_document_editor_saves set state = ?',
            [],
            new PDOException('ERROR: check violation'),
        );
        $errorInfoState->errorInfo = ['23514', 0, 'ERROR: check violation'];

        self::assertSame('sqlstate_23514', $method->invoke($controller, $errorInfoState));
        $constraintError = new QueryException(
            'pgsql',
            'update legal_document_editor_saves set state = ?',
            [],
            new PDOException('ERROR: duplicate key value violates unique constraint "legal_editor_saves_saved_version_unique"'),
        );

        self::assertSame('constraint_legal_editor_saves_saved_version_unique', $method->invoke($controller, $constraintError));

        $unknownTableError = new QueryException(
            'pgsql',
            'update "legal_document_editor_saves" set "state" = ?',
            [],
            new PDOException('ERROR: database operation failed'),
        );

        self::assertSame('query_legal_document_editor_saves', $method->invoke($controller, $unknownTableError));

        $mixedCaseTableError = new QueryException(
            'pgsql',
            'update "Legal_Document_Editor_Saves" set "state" = ?',
            [],
            new PDOException('ERROR: database operation failed'),
        );

        self::assertNull($method->invoke($controller, $mixedCaseTableError));

        $tablePrefixError = new QueryException(
            'pgsql',
            'update legal_document_editor_savesX set "state" = ?',
            [],
            new PDOException('ERROR: database operation failed'),
        );

        self::assertNull($method->invoke($controller, $tablePrefixError));

        $dollarSuffixTableError = new QueryException(
            'pgsql',
            'update legal_document_editor_saves$shadow set "state" = ?',
            [],
            new PDOException('ERROR: database operation failed'),
        );

        self::assertNull($method->invoke($controller, $dollarSuffixTableError));
        self::assertNull($method->invoke($controller, new RuntimeException('database unavailable')));
    }
}
