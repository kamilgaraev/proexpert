<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Files\Schema;

final class LegalDocumentVersionOperationPostgresSchema
{
    public const TABLE = 'legal_archive_document_version_operations';

    /** @return array<string, array{type: string, nullable: bool, default: ?string, sql: string}> */
    public static function columns(): array
    {
        return [
            'id' => ['type' => 'bigint', 'nullable' => false, 'default' => "nextval('legal_archive_document_version_operations_id_seq'::regclass)", 'sql' => 'BIGSERIAL'],
            'organization_id' => ['type' => 'bigint', 'nullable' => false, 'default' => null, 'sql' => 'BIGINT NOT NULL'],
            'document_id' => ['type' => 'bigint', 'nullable' => false, 'default' => null, 'sql' => 'BIGINT NOT NULL'],
            'document_file_id' => ['type' => 'bigint', 'nullable' => false, 'default' => null, 'sql' => 'BIGINT NOT NULL'],
            'operation_id' => ['type' => 'character varying(191)', 'nullable' => false, 'default' => null, 'sql' => 'VARCHAR(191) NOT NULL'],
            'operation_generation' => ['type' => 'integer', 'nullable' => false, 'default' => '1', 'sql' => 'INTEGER NOT NULL DEFAULT 1'],
            'request_fingerprint' => ['type' => 'character varying(64)', 'nullable' => false, 'default' => null, 'sql' => 'VARCHAR(64) NOT NULL'],
            'requested_version_number' => ['type' => 'character varying(64)', 'nullable' => true, 'default' => null, 'sql' => 'VARCHAR(64) NULL'],
            'reserved_version_number' => ['type' => 'character varying(255)', 'nullable' => false, 'default' => null, 'sql' => 'VARCHAR(255) NOT NULL'],
            'version_label' => ['type' => 'text', 'nullable' => true, 'default' => null, 'sql' => 'TEXT NULL'],
            'uploaded_by_user_id' => ['type' => 'bigint', 'nullable' => true, 'default' => null, 'sql' => 'BIGINT NULL'],
            'version_metadata' => ['type' => 'jsonb', 'nullable' => true, 'default' => null, 'sql' => 'JSONB NULL'],
            'file_original_name' => ['type' => 'text', 'nullable' => false, 'default' => null, 'sql' => 'TEXT NOT NULL'],
            'file_size_bytes' => ['type' => 'bigint', 'nullable' => false, 'default' => null, 'sql' => 'BIGINT NOT NULL'],
            'file_content_hash' => ['type' => 'character(64)', 'nullable' => false, 'default' => null, 'sql' => 'CHAR(64) NOT NULL'],
            'file_client_mime_type' => ['type' => 'character varying(255)', 'nullable' => true, 'default' => null, 'sql' => 'VARCHAR(255) NULL'],
            'file_detected_mime_type' => ['type' => 'character varying(255)', 'nullable' => true, 'default' => null, 'sql' => 'VARCHAR(255) NULL'],
            'make_current' => ['type' => 'boolean', 'nullable' => false, 'default' => null, 'sql' => 'BOOLEAN NOT NULL'],
            'attempt_token' => ['type' => 'character varying(191)', 'nullable' => false, 'default' => null, 'sql' => 'VARCHAR(191) NOT NULL'],
            'attempt_count' => ['type' => 'integer', 'nullable' => false, 'default' => '1', 'sql' => 'INTEGER NOT NULL DEFAULT 1'],
            'status' => ['type' => 'character varying(32)', 'nullable' => false, 'default' => null, 'sql' => 'VARCHAR(32) NOT NULL'],
            'storage_path' => ['type' => 'text', 'nullable' => true, 'default' => null, 'sql' => 'TEXT NULL'],
            'document_version_id' => ['type' => 'bigint', 'nullable' => true, 'default' => null, 'sql' => 'BIGINT NULL'],
            'created_at' => ['type' => 'timestamp with time zone', 'nullable' => true, 'default' => null, 'sql' => 'TIMESTAMPTZ NULL'],
            'updated_at' => ['type' => 'timestamp with time zone', 'nullable' => true, 'default' => null, 'sql' => 'TIMESTAMPTZ NULL'],
        ];
    }

    /** @return array<string, array{unique: bool, primary: bool, keys: list<string>, sql: string}> */
    public static function indexes(): array
    {
        return [
            'legal_archive_document_version_operations_pkey' => [
                'unique' => true, 'primary' => true, 'keys' => ['id'],
                'sql' => 'ALTER TABLE '.self::TABLE.' ADD CONSTRAINT legal_archive_document_version_operations_pkey PRIMARY KEY (id)',
            ],
            'legal_archive_version_operation_identity_unique' => [
                'unique' => true, 'primary' => false,
                'keys' => ['organization_id', 'document_file_id', 'operation_id', 'operation_generation'],
                'sql' => 'CREATE UNIQUE INDEX legal_archive_version_operation_identity_unique ON '.self::TABLE.' (organization_id, document_file_id, operation_id, operation_generation)',
            ],
            'legal_archive_version_operation_slot_unique' => [
                'unique' => true, 'primary' => false, 'keys' => ['document_file_id', 'reserved_version_number'],
                'sql' => 'CREATE UNIQUE INDEX legal_archive_version_operation_slot_unique ON '.self::TABLE.' (document_file_id, reserved_version_number)',
            ],
            'legal_archive_version_operations_status' => [
                'unique' => false, 'primary' => false, 'keys' => ['organization_id', 'document_id', 'status'],
                'sql' => 'CREATE INDEX legal_archive_version_operations_status ON '.self::TABLE.' (organization_id, document_id, status)',
            ],
        ];
    }

    /** @return list<array{table: string, name: string, definition: string, type: string, deferrable: bool, deferred: bool, keys: ?list<string>, referenced_table: ?string, referenced_keys: ?list<string>, update_action: ?string, delete_action: ?string, match_type: ?string}> */
    public static function constraints(): array
    {
        $definitions = [
            ['legal_archive_version_operations_document_fk', 'FOREIGN KEY (document_id, organization_id) REFERENCES legal_archive_documents (id, organization_id) ON DELETE RESTRICT', ['document_id', 'organization_id'], 'legal_archive_documents', ['id', 'organization_id']],
            ['legal_archive_version_operations_file_fk', 'FOREIGN KEY (document_file_id, document_id, organization_id) REFERENCES legal_archive_document_files (id, document_id, organization_id) ON DELETE RESTRICT', ['document_file_id', 'document_id', 'organization_id'], 'legal_archive_document_files', ['id', 'document_id', 'organization_id']],
            ['legal_archive_version_operations_version_fk', 'FOREIGN KEY (document_version_id, document_file_id, organization_id) REFERENCES legal_archive_document_versions (id, document_file_id, organization_id) ON DELETE RESTRICT', ['document_version_id', 'document_file_id', 'organization_id'], 'legal_archive_document_versions', ['id', 'document_file_id', 'organization_id']],
            ['legal_archive_version_operations_status_check', "CHECK (status IN ('reserved', 'quarantine', 'completed', 'failed'))", ['status'], null, null],
            ['legal_archive_version_operations_state_check', "CHECK ((status = 'reserved' AND storage_path IS NULL AND document_version_id IS NULL) OR (status IN ('quarantine', 'completed', 'failed') AND storage_path IS NOT NULL AND document_version_id IS NOT NULL))", ['status', 'storage_path', 'document_version_id'], null, null],
        ];

        return array_map(static fn (array $item): array => [
            'table' => self::TABLE,
            'name' => $item[0],
            'definition' => $item[1],
            'type' => str_starts_with($item[1], 'FOREIGN KEY') ? 'f' : 'c',
            'deferrable' => false,
            'deferred' => false,
            'keys' => $item[2],
            'referenced_table' => $item[3],
            'referenced_keys' => $item[4],
            'update_action' => $item[3] === null ? null : 'a',
            'delete_action' => $item[3] === null ? null : 'r',
            'match_type' => $item[3] === null ? null : 's',
        ], $definitions);
    }

    /** @param array{table: string, name: string, definition: string, type: string, deferrable: bool, deferred: bool, keys: ?list<string>, referenced_table: ?string, referenced_keys: ?list<string>, update_action: ?string, delete_action: ?string, match_type: ?string} $expected */
    public static function constraintMatches(object $actual, array $expected, ?bool $validated = null): bool
    {
        if ($expected['name'] === 'legal_archive_version_operations_state_check') {
            return $actual->table_name === $expected['table']
                && $actual->contype === $expected['type']
                && ! (bool) $actual->condeferrable
                && ! (bool) $actual->condeferred
                && ($validated === null || (bool) $actual->convalidated === $validated);
        }

        return $actual->table_name === $expected['table']
            && $actual->contype === $expected['type']
            && (bool) $actual->condeferrable === $expected['deferrable']
            && (bool) $actual->condeferred === $expected['deferred']
            && ($validated === null || (bool) $actual->convalidated === $validated)
            && self::names($actual->key_columns ?? null) === ($expected['keys'] ?? [])
            && ($actual->referenced_schema ?? null) === ($expected['referenced_table'] === null ? null : $actual->table_schema)
            && ($actual->referenced_table ?? null) === $expected['referenced_table']
            && self::names($actual->referenced_key_columns ?? null) === ($expected['referenced_keys'] ?? [])
            && ($expected['update_action'] === null || $actual->confupdtype === $expected['update_action'])
            && ($expected['delete_action'] === null || $actual->confdeltype === $expected['delete_action'])
            && ($expected['match_type'] === null || $actual->confmatchtype === $expected['match_type'])
            && (
                self::normalize($actual->definition) === self::normalize($expected['definition'])
                || ($expected['name'] === 'legal_archive_version_operations_state_check' && self::compatibleStateConstraint($actual->definition))
            );
    }

    /** @return list<string> */
    private static function names(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }
        if (! is_string($value)) {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    private static function normalize(mixed $definition): string
    {
        $normalized = strtolower((string) $definition);
        $normalized = str_replace('not valid', '', $normalized);
        $normalized = (string) preg_replace('/::[a-z_ ]+(?:\[\])?/', '', $normalized);
        $normalized = (string) preg_replace('/["\s()]+/', '', $normalized);

        return str_replace(['=anyarray[', ']'], ['in', ''], $normalized);
    }

}
