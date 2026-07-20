<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Services\LegalArchive\Files\Schema\LegalDocumentVersionOperationPostgresSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegalDocumentVersionOperationSchemaTest extends TestCase
{
    public function test_schema_contract_is_complete_and_forward_only(): void
    {
        $root = dirname(__DIR__, 3).DIRECTORY_SEPARATOR;
        $create = file_get_contents($root.'database/migrations/2026_07_19_000250_create_legal_document_version_operations.php');
        $constraints = file_get_contents($root.'database/migrations/2026_07_19_000260_add_legal_document_version_operation_constraints.php');
        $validate = file_get_contents($root.'database/migrations/2026_07_19_000270_validate_legal_document_version_operation_constraints.php');
        $rescan = file_get_contents($root.'database/migrations/2026_07_19_000280_allow_fenced_legal_document_version_rescan.php');

        foreach ([$create, $constraints, $validate, $rescan] as $source) {
            self::assertIsString($source);
            self::assertStringContainsString('legal_archive_version_operation_migrations_are_forward_only', $source);
        }
        self::assertStringContainsString('columnDescriptor', $create);
        self::assertStringContainsString('indexDescriptor', $create);
        self::assertStringContainsString('relationDescriptor', $create);
        self::assertStringContainsString('pg_sequence', $create);
        self::assertStringContainsString('seqincrement', $create);
        self::assertStringContainsString('seqcycle', $create);
        self::assertStringContainsString('pg_get_constraintdef', $constraints);
        self::assertStringContainsString('pg_get_constraintdef', $validate);
        self::assertStringContainsString('pg_get_functiondef', $rescan);
        self::assertStringContainsString('pg_get_triggerdef', $rescan);
        self::assertStringContainsString('prosecdef', $rescan);
        self::assertStringContainsString('provolatile', $rescan);
        self::assertStringContainsString('proconfig', $rescan);
    }

    public function test_constraint_descriptor_rejects_wrong_relation_definition_and_validity_phase(): void
    {
        $expected = LegalDocumentVersionOperationPostgresSchema::constraints()[0];
        $actual = (object) [
            'table_schema' => 'public',
            'table_name' => $expected['table'],
            'contype' => $expected['type'],
            'condeferrable' => 0,
            'condeferred' => 0,
            'convalidated' => 0,
            'definition' => $expected['definition'].' NOT VALID',
            'key_columns' => json_encode($expected['keys']),
            'referenced_schema' => 'public',
            'referenced_table' => $expected['referenced_table'],
            'referenced_key_columns' => json_encode($expected['referenced_keys']),
            'confupdtype' => $expected['update_action'],
            'confdeltype' => $expected['delete_action'],
            'confmatchtype' => $expected['match_type'],
        ];

        self::assertTrue(LegalDocumentVersionOperationPostgresSchema::constraintMatches($actual, $expected, false));
        self::assertFalse(LegalDocumentVersionOperationPostgresSchema::constraintMatches($actual, $expected, true));
        $actual->table_name = 'legal_archive_documents';
        self::assertFalse(LegalDocumentVersionOperationPostgresSchema::constraintMatches($actual, $expected, false));
    }

    #[DataProvider('constraintDriftProvider')]
    public function test_constraint_descriptor_rejects_drift(string $field, mixed $value): void
    {
        $expected = LegalDocumentVersionOperationPostgresSchema::constraints()[0];
        $actual = (object) [
            'table_schema' => 'public',
            'table_name' => $expected['table'],
            'contype' => $expected['type'],
            'condeferrable' => 0,
            'condeferred' => 0,
            'convalidated' => 0,
            'definition' => $expected['definition'],
            'key_columns' => json_encode($expected['keys']),
            'referenced_schema' => 'public',
            'referenced_table' => $expected['referenced_table'],
            'referenced_key_columns' => json_encode($expected['referenced_keys']),
            'confupdtype' => $expected['update_action'],
            'confdeltype' => $expected['delete_action'],
            'confmatchtype' => $expected['match_type'],
        ];
        $actual->{$field} = $value;

        self::assertFalse(LegalDocumentVersionOperationPostgresSchema::constraintMatches($actual, $expected, false));
    }

    public static function constraintDriftProvider(): iterable
    {
        yield 'type' => ['contype', 'c'];
        yield 'deferrable' => ['condeferrable', 1];
        yield 'deferred' => ['condeferred', 1];
        yield 'definition' => ['definition', 'FOREIGN KEY (document_id) REFERENCES legal_archive_documents(id)'];
        yield 'keys' => ['key_columns', '["organization_id","document_id"]'];
        yield 'referenced schema' => ['referenced_schema', 'other'];
        yield 'referenced table' => ['referenced_table', 'legal_archive_document_files'];
        yield 'referenced keys' => ['referenced_key_columns', '["organization_id","id"]'];
        yield 'update action' => ['confupdtype', 'c'];
        yield 'delete action' => ['confdeltype', 'c'];
        yield 'match type' => ['confmatchtype', 'f'];
    }
}
