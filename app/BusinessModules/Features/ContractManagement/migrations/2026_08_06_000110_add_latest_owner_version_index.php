<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'contract_settlement_owner_latest_asof_idx';

    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $this->dropInvalidPostgresIndex();
            DB::statement(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::INDEX.' '
                .'ON contract_settlement_owner_versions '
                .'(organization_id, owner_type, owner_id, version DESC, occurred_at) INCLUDE (id)',
            );

            return;
        }

        Schema::table('contract_settlement_owner_versions', function (Blueprint $table): void {
            $table->index(
                ['organization_id', 'owner_type', 'owner_id', 'version', 'occurred_at'],
                self::INDEX,
            );
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);

            return;
        }

        Schema::table('contract_settlement_owner_versions', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }

    private function dropInvalidPostgresIndex(): void
    {
        $invalid = DB::selectOne(
            'SELECT 1 FROM pg_index AS index_state '
            .'INNER JOIN pg_class AS index_class ON index_class.oid = index_state.indexrelid '
            .'INNER JOIN pg_namespace AS index_namespace ON index_namespace.oid = index_class.relnamespace '
            .'WHERE index_namespace.nspname = current_schema() '
            .'AND index_class.relname = ? AND index_state.indisvalid = false',
            [self::INDEX],
        );
        if ($invalid !== null) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
        }
    }
};
