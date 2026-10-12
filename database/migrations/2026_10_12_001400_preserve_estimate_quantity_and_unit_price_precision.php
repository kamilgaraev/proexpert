<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Estimate decimal precision requires PostgreSQL.');
        }
        $columns = ['quantity' => [24, 8], 'quantity_coefficient' => [24, 8], 'quantity_total' => [24, 8],
            'actual_quantity' => [24, 8], 'unit_price' => [24, 4], 'base_unit_price' => [24, 4],
            'current_unit_price' => [24, 4], 'actual_unit_price' => [24, 4]];
        $current = DB::table('information_schema.columns')->whereRaw('table_schema = current_schema()')
            ->where('table_name', 'estimate_items')->whereIn('column_name', array_keys($columns))->get()->keyBy('column_name');
        $changes = [];
        $changesQuantity = false;
        foreach ($columns as $column => [$precision, $scale]) {
            $definition = $current[$column] ?? null;
            if ($definition === null) {
                throw new RuntimeException('Estimate decimal column is missing: '.$column);
            }
            if ((int) $definition->numeric_precision < $precision || (int) $definition->numeric_scale < $scale) {
                $changes[] = 'ALTER COLUMN '.$column.' TYPE numeric('.$precision.','.$scale.')';
                $changesQuantity = $changesQuantity || $column === 'quantity';
            }
        }
        if ($changes !== []) {
            DB::transaction(function () use ($changes, $changesQuantity): void {
                if (! $changesQuantity) {
                    DB::statement('ALTER TABLE estimate_items '.implode(', ', $changes));

                    return;
                }
                // PostgreSQL retains column dependencies even when only numeric precision is widened.
                // Preserve the existing projection; unknown dependants must fail instead of being cascaded away.
                $view = DB::selectOne(<<<'SQL'
                    SELECT c.oid, n.nspname AS schema_name, pg_get_userbyid(c.relowner) AS owner_name,
                           pg_get_viewdef(c.oid, true) AS definition, c.relispopulated AS populated,
                           obj_description(c.oid, 'pg_class') AS comment, c.reloptions, c.reltablespace
                    FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                    WHERE n.nspname = current_schema() AND c.relname = 'mv_normative_rates_usage' AND c.relkind = 'm'
                    SQL);
                $indexes = $view === null ? [] : DB::select('SELECT pg_get_indexdef(indexrelid) AS definition FROM pg_index WHERE indrelid = ? ORDER BY indexrelid', [$view->oid]);
                $grants = $view === null ? [] : DB::select(<<<'SQL'
                    SELECT CASE WHEN acl.grantee = 0 THEN NULL ELSE pg_get_userbyid(acl.grantee) END AS role_name,
                           acl.privilege_type, acl.is_grantable
                    FROM pg_class c CROSS JOIN LATERAL aclexplode(COALESCE(c.relacl, acldefault('r', c.relowner))) acl
                    WHERE c.oid = ?
                    SQL, [$view->oid]);
                if ($view !== null && ($view->reloptions !== null || (int) $view->reltablespace !== 0)) {
                    throw new RuntimeException('Custom normative usage projection storage requires an explicit precision migration.');
                }
                $name = $view === null ? null : $this->identifier($view->schema_name).'.'.$this->identifier('mv_normative_rates_usage');
                if ($name !== null) {
                    DB::statement('DROP MATERIALIZED VIEW '.$name);
                }
                DB::statement('ALTER TABLE estimate_items '.implode(', ', $changes));
                if ($view === null || $name === null) {
                    return;
                }
                DB::statement('CREATE MATERIALIZED VIEW '.$name.' AS '.rtrim($view->definition, '; ').($view->populated ? ' WITH DATA' : ' WITH NO DATA'));
                foreach ($indexes as $index) {
                    DB::statement($index->definition);
                }
                DB::statement('ALTER MATERIALIZED VIEW '.$name.' OWNER TO '.$this->identifier($view->owner_name));
                DB::statement('REVOKE ALL ON '.$name.' FROM PUBLIC');
                foreach ($grants as $grant) {
                    if (! in_array($grant->privilege_type, ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'], true)) {
                        throw new RuntimeException('Unsupported normative usage projection grant.');
                    }
                    $role = $grant->role_name === null ? 'PUBLIC' : $this->identifier($grant->role_name);
                    DB::statement('GRANT '.$grant->privilege_type.' ON '.$name.' TO '.$role.($grant->is_grantable ? ' WITH GRANT OPTION' : ''));
                }
                if ($view->comment !== null) {
                    DB::statement('COMMENT ON MATERIALIZED VIEW '.$name.' IS '.DB::connection()->getPdo()->quote($view->comment));
                }
            });
        }
    }

    private function identifier(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }

    public function down(): void
    {
        throw new RuntimeException('Accepted estimate precision is forward-only; narrowing would destroy source money.');
    }
};
