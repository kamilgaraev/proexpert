<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workforce_payroll_periods', function (Blueprint $table): void {
            $table->unsignedInteger('revision')->default(1);
        });
        Schema::table('workforce_export_packages', function (Blueprint $table): void {
            $table->unsignedInteger('payroll_revision')->default(1);
        });
        foreach (['workforce_absences', 'workforce_business_trips'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('reason_code', 80)->nullable();
                $table->string('basis_reference')->nullable();
                $table->foreignId('basis_document_id')->nullable()->constrained('workforce_hr_documents')->restrictOnDelete();
            });
        }

        Schema::create('workforce_payroll_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('payroll_period_id')->constrained('workforce_payroll_periods')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('snapshot_sha256', 64);
            $table->jsonb('snapshot');
            $table->string('basis_reference');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('recorded_at');
            $table->unique(['payroll_period_id', 'revision']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE FUNCTION protect_workforce_payroll_revision() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'workforce_payroll_revision_immutable'; END; $$;
CREATE TRIGGER workforce_payroll_revision_immutable BEFORE UPDATE OR DELETE ON workforce_payroll_revisions
FOR EACH ROW EXECUTE FUNCTION protect_workforce_payroll_revision();
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_payroll_revisions');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS protect_workforce_payroll_revision()');
        }
        Schema::table('workforce_export_packages', fn (Blueprint $table) => $table->dropColumn('payroll_revision'));
        Schema::table('workforce_payroll_periods', fn (Blueprint $table) => $table->dropColumn('revision'));
        foreach (['workforce_absences', 'workforce_business_trips'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('basis_document_id');
                $table->dropColumn(['reason_code', 'basis_reference']);
            });
        }
    }
};
