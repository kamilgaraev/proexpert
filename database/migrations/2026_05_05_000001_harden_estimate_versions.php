<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ORGANIZATION_ESTIMATE_VERSION_INDEX = 'estimate_versions_org_estimate_version_idx';
    private const ESTIMATE_SNAPSHOT_HASH_INDEX = 'estimate_versions_estimate_snapshot_hash_idx';

    public function up(): void
    {
        if (!Schema::hasTable('estimate_versions')) {
            return;
        }

        Schema::table('estimate_versions', function (Blueprint $table) {
            if (!Schema::hasColumn('estimate_versions', 'organization_id')) {
                $table->foreignId('organization_id')
                    ->nullable()
                    ->constrained('organizations')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('estimate_versions', 'snapshot_hash')) {
                $table->string('snapshot_hash', 64)->nullable()->after('snapshot');
            }

            if (!Schema::hasColumn('estimate_versions', 'snapshot_type')) {
                $table->string('snapshot_type', 40)->default('manual');
            }

            if (!Schema::hasColumn('estimate_versions', 'estimate_status')) {
                $table->string('estimate_status', 40)->nullable();
            }

            if (!Schema::hasColumn('estimate_versions', 'approved_by_user_id')) {
                $table->foreignId('approved_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('estimate_versions', 'approved_at')) {
                $table->timestamp('approved_at')->nullable();
            }
        });

        Schema::table('estimate_versions', function (Blueprint $table) {
            if (!Schema::hasIndex('estimate_versions', self::ORGANIZATION_ESTIMATE_VERSION_INDEX)) {
                $table->index(
                    ['organization_id', 'estimate_id', 'version_number'],
                    self::ORGANIZATION_ESTIMATE_VERSION_INDEX
                );
            }

            if (!Schema::hasIndex('estimate_versions', self::ESTIMATE_SNAPSHOT_HASH_INDEX)) {
                $table->index(['estimate_id', 'snapshot_hash'], self::ESTIMATE_SNAPSHOT_HASH_INDEX);
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('estimate_versions')) {
            return;
        }

        Schema::table('estimate_versions', function (Blueprint $table) {
            if (Schema::hasIndex('estimate_versions', self::ORGANIZATION_ESTIMATE_VERSION_INDEX)) {
                $table->dropIndex(self::ORGANIZATION_ESTIMATE_VERSION_INDEX);
            }

            if (Schema::hasIndex('estimate_versions', self::ESTIMATE_SNAPSHOT_HASH_INDEX)) {
                $table->dropIndex(self::ESTIMATE_SNAPSHOT_HASH_INDEX);
            }
        });

        $this->dropForeignKeyColumnIfExists('organization_id');
        $this->dropForeignKeyColumnIfExists('approved_by_user_id');

        $columns = array_values(array_filter([
            Schema::hasColumn('estimate_versions', 'snapshot_hash') ? 'snapshot_hash' : null,
            Schema::hasColumn('estimate_versions', 'snapshot_type') ? 'snapshot_type' : null,
            Schema::hasColumn('estimate_versions', 'estimate_status') ? 'estimate_status' : null,
            Schema::hasColumn('estimate_versions', 'approved_at') ? 'approved_at' : null,
        ]));

        if ($columns !== []) {
            Schema::table('estimate_versions', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }

    private function dropForeignKeyColumnIfExists(string $column): void
    {
        if (!Schema::hasColumn('estimate_versions', $column)) {
            return;
        }

        $foreignKeyName = $this->findForeignKeyName('estimate_versions', $column);

        if ($foreignKeyName !== null) {
            Schema::table('estimate_versions', function (Blueprint $table) use ($foreignKeyName) {
                $table->dropForeign($foreignKeyName);
            });
        }

        Schema::table('estimate_versions', function (Blueprint $table) use ($column) {
            $table->dropColumn($column);
        });
    }

    private function findForeignKeyName(string $table, string $column): ?string
    {
        if (DB::getDriverName() !== 'pgsql') {
            return null;
        }

        $constraints = DB::select(
            <<<'SQL'
                SELECT tc.constraint_name
                FROM information_schema.table_constraints AS tc
                INNER JOIN information_schema.key_column_usage AS kcu
                    ON tc.constraint_schema = kcu.constraint_schema
                    AND tc.constraint_name = kcu.constraint_name
                WHERE tc.constraint_type = 'FOREIGN KEY'
                    AND tc.table_schema = current_schema()
                    AND tc.table_name = ?
                    AND kcu.column_name = ?
                LIMIT 1
            SQL,
            [$table, $column]
        );

        return $constraints[0]->constraint_name ?? null;
    }
};
