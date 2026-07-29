<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workforce_payroll_periods', function (Blueprint $table): void {
            $table->char('currency', 3)->default('RUB')->after('period_end');
        });

        Schema::create('workforce_payroll_readiness_snapshots', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('payroll_period_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->date('period_from');
            $table->date('period_to');
            $table->char('currency', 3);
            $table->string('currency_source', 64);
            $table->char('owner_source_hash', 64);
            $table->char('source_hash', 64);
            $table->unsignedInteger('row_count');
            $table->unsignedInteger('blocking_issue_count');
            $table->dateTimeTz('locked_at');
            $table->timestampsTz();

            $table->unique(
                ['organization_id', 'payroll_period_id', 'owner_source_hash'],
                'workforce_payroll_readiness_snapshot_identity_unique',
            );
            $table->index(
                ['organization_id', 'period_from', 'period_to', 'currency', 'id'],
                'workforce_payroll_readiness_snapshot_scope_idx',
            );
        });

        Schema::create('workforce_payroll_readiness_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('snapshot_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('source_row_id');
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('project_id');
            $table->date('work_date');
            $table->decimal('hours', 18, 2);
            $table->bigInteger('amount_minor');
            $table->char('source_hash', 64);
            $table->timestampsTz();

            $table->foreign('snapshot_id')->references('id')->on('workforce_payroll_readiness_snapshots');
            $table->unique(
                ['organization_id', 'snapshot_id', 'source_row_id'],
                'workforce_payroll_readiness_row_identity_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_payroll_readiness_rows');
        Schema::dropIfExists('workforce_payroll_readiness_snapshots');

        Schema::table('workforce_payroll_periods', function (Blueprint $table): void {
            $table->dropColumn('currency');
        });
    }
};
