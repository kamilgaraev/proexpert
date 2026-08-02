<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entry_approval_reporting_facts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('time_entry_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedBigInteger('work_type_id')->nullable();
            $table->date('work_date');
            $table->char('currency', 3);
            $table->string('currency_source', 64);
            $table->decimal('hours', 18, 2);
            $table->bigInteger('hourly_rate_minor')->nullable();
            $table->bigInteger('cost_minor')->nullable();
            $table->string('quality_status', 16);
            $table->dateTimeTz('approved_at');
            $table->char('source_hash', 64);
            $table->timestampsTz();

            $table->unique(['organization_id', 'time_entry_id'], 'time_entry_reporting_fact_identity_unique');
            $table->index(
                ['organization_id', 'project_id', 'work_date', 'currency', 'id'],
                'time_entry_reporting_fact_scope_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entry_approval_reporting_facts');
    }
};
