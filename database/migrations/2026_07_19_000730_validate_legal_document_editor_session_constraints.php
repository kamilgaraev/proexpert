<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }
        $schemaMigration = require __DIR__.'/2026_07_19_000700_create_legal_document_editor_sessions.php';
        $indexMigration = require __DIR__.'/2026_07_19_000710_create_legal_document_editor_session_indexes.php';
        $constraintMigration = require __DIR__.'/2026_07_19_000720_add_legal_document_editor_session_constraints.php';

        $schemaMigration->verifySchemaManifest();
        $indexMigration->assertIndexManifest();
        $constraintMigration->assertInstalledManifest();

        foreach ($this->manifest() as $table => $names) {
            foreach ($names as $name) {
                $constraint = DB::selectOne(<<<'SQL'
SELECT c.convalidated::integer validated FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid
JOIN pg_namespace n ON n.oid=t.relnamespace WHERE n.nspname=current_schema() AND t.relname=? AND c.conname=?
SQL, [$table, $name]);
                if ($constraint === null) {
                    throw new RuntimeException("legal_document_editor_constraint_missing:{$name}");
                }
                if (! (bool) $constraint->validated) {
                    DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$name}");
                }
            }
        }

        $schemaMigration->verifySchemaManifest();
        $indexMigration->assertIndexManifest();
        $constraintMigration->assertInstalledManifest();
        foreach ($this->manifest() as $table => $names) {
            foreach ($names as $name) {
                $validated = DB::selectOne(<<<'SQL'
SELECT c.convalidated::integer validated FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid
JOIN pg_namespace n ON n.oid=t.relnamespace WHERE n.nspname=current_schema() AND t.relname=? AND c.conname=?
SQL, [$table, $name]);
                if ($validated === null || ! (bool) $validated->validated) {
                    throw new RuntimeException("legal_document_editor_constraint_not_validated:{$name}");
                }
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('legal_document_editor_session_validation_forward_only');
    }

    private function manifest(): array
    {
        return [
            'legal_document_editor_sessions' => [
                'legal_editor_sessions_status_check', 'legal_editor_sessions_mode_check',
                'legal_editor_sessions_generation_check', 'legal_editor_sessions_hash_check',
                'legal_editor_sessions_state_check', 'legal_editor_sessions_time_check',
                'legal_editor_sessions_document_fk', 'legal_editor_sessions_source_version_fk',
                'legal_editor_sessions_saved_version_fk', 'legal_editor_sessions_actor_fk',
            ],
            'legal_document_editor_participants' => [
                'legal_editor_participants_session_fk', 'legal_editor_participants_user_fk',
                'legal_editor_participants_actor_key_check', 'legal_editor_participants_ability_check',
                'legal_editor_participants_time_check',
            ],
            'legal_document_editor_saves' => [
                'legal_editor_saves_session_fk', 'legal_editor_saves_source_version_fk',
                'legal_editor_saves_saved_version_fk', 'legal_editor_saves_generation_check',
                'legal_editor_saves_callback_check', 'legal_editor_saves_state_check',
                'legal_editor_saves_hash_check', 'legal_editor_saves_lease_check',
                'legal_editor_saves_result_check', 'legal_editor_saves_time_check',
            ],
        ];
    }
};
