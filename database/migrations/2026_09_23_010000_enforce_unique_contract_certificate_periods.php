<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('contract_period_certificates')
            ->select(['contract_id', 'project_id', 'period_start', 'period_end'])
            ->groupBy(['contract_id', 'project_id', 'period_start', 'period_end'])
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->exists();

        if ($duplicates) {
            throw new RuntimeException('Duplicate contract certificate periods must be reviewed before migration.');
        }

        DB::statement('DROP INDEX IF EXISTS contract_period_certificates_identity_with_project');
        DB::statement('DROP INDEX IF EXISTS contract_period_certificates_identity_without_project');
        DB::statement(
            'CREATE UNIQUE INDEX contract_period_certificates_period_unique
             ON contract_period_certificates (contract_id, project_id, period_start, period_end) NULLS NOT DISTINCT'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS contract_period_certificates_period_unique');
        DB::statement(
            'CREATE UNIQUE INDEX contract_period_certificates_identity_with_project
             ON contract_period_certificates (contract_id, project_id, period_start, period_end, version_number)
             WHERE project_id IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX contract_period_certificates_identity_without_project
             ON contract_period_certificates (contract_id, period_start, period_end, version_number)
             WHERE project_id IS NULL'
        );
    }
};
