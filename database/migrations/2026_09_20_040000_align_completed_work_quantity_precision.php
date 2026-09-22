<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SUPPORTED_PRIVILEGES = [
        'SELECT',
        'INSERT',
        'UPDATE',
        'DELETE',
        'TRUNCATE',
        'REFERENCES',
        'TRIGGER',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('completed_works', function (Blueprint $table): void {
                $table->decimal('quantity', 18, 4)->change();
                $table->decimal('completed_quantity', 18, 4)->nullable()->change();
            });

            return;
        }

        if ($this->alreadyAligned()) {
            return;
        }

        $view = DB::selectOne(
            "SELECT pg_get_viewdef(c.oid) AS definition, pg_get_userbyid(c.relowner) AS owner, array_to_string(c.reloptions, ',') AS options FROM pg_class c WHERE c.oid = to_regclass('cross_org_completed_works') AND c.relkind = 'v'"
        );
        $grants = $view
            ? DB::select(
                "SELECT CASE WHEN a.grantee = 0 THEN 'PUBLIC' ELSE pg_get_userbyid(a.grantee) END AS grantee, a.privilege_type, CASE WHEN a.is_grantable THEN 'YES' ELSE 'NO' END AS is_grantable FROM pg_class c CROSS JOIN LATERAL aclexplode(c.relacl) a WHERE c.oid = to_regclass('cross_org_completed_works')"
            )
            : [];

        if ($view) {
            DB::statement('DROP VIEW IF EXISTS cross_org_completed_works');
        }

        Schema::table('completed_works', function (Blueprint $table): void {
            $table->decimal('quantity', 18, 4)->change();
            $table->decimal('completed_quantity', 18, 4)->nullable()->change();
        });

        if (! $view) {
            return;
        }

        DB::statement('CREATE VIEW cross_org_completed_works AS '.$view->definition);
        if ($view->options) {
            DB::statement('ALTER VIEW cross_org_completed_works SET ('.$view->options.')');
        }

        foreach ($grants as $grant) {
            if (! in_array($grant->privilege_type, self::SUPPORTED_PRIVILEGES, true)) {
                continue;
            }
            $role = $grant->grantee === 'PUBLIC' ? 'PUBLIC' : $this->identifier($grant->grantee);
            DB::statement(
                'GRANT '.$grant->privilege_type.' ON cross_org_completed_works TO '.$role
                .($grant->is_grantable === 'YES' ? ' WITH GRANT OPTION' : '')
            );
        }

        DB::statement('ALTER VIEW cross_org_completed_works OWNER TO '.$this->identifier($view->owner));
    }

    public function down(): void {}

    private function alreadyAligned(): bool
    {
        $columns = DB::select(
            "SELECT column_name, numeric_precision, numeric_scale FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'completed_works' AND column_name IN ('quantity', 'completed_quantity')"
        );
        if (count($columns) < 2) {
            return false;
        }

        foreach ($columns as $column) {
            if ((int) $column->numeric_precision !== 18 || (int) $column->numeric_scale !== 4) {
                return false;
            }
        }

        return true;
    }

    private function identifier(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }
};
