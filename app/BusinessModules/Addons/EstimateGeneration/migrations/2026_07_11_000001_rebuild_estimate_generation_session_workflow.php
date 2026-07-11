<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        DB::table('estimate_generation_feedback')->delete();
        DB::table('estimate_generation_audit_events')->delete();
        DB::table('estimate_generation_package_items')->delete();
        DB::table('estimate_generation_packages')->delete();
        DB::table('estimate_generation_drawing_elements')->delete();
        DB::table('estimate_generation_quantity_takeoffs')->delete();
        DB::table('estimate_generation_scope_inferences')->delete();
        DB::table('estimate_generation_document_facts')->delete();
        DB::table('estimate_generation_document_pages')->delete();
        DB::table('estimate_generation_documents')->delete();
        DB::table('estimate_generation_sessions')->delete();

        Schema::table('estimate_generation_sessions', function (Blueprint $table): void {
            $table->string('status', 50)->default('draft')->change();
            $table->unsignedBigInteger('state_version')->default(0);
            $table->timestampTz('applied_at')->nullable();
            $table->timestampTz('state_changed_at')->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->string('resume_status', 40)->nullable();
            $table->unique('applied_estimate_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE estimate_generation_sessions
            ADD CONSTRAINT estimate_generation_sessions_status_check
            CHECK (status IN (
                'draft',
                'processing_documents',
                'input_review_required',
                'ready_to_generate',
                'generating',
                'estimate_review_required',
                'ready_to_apply',
                'applying',
                'applied',
                'failed',
                'cancelled',
                'archived'
            ))
            SQL);
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE estimate_generation_sessions '
            . 'DROP CONSTRAINT estimate_generation_sessions_status_check'
        );

        Schema::table('estimate_generation_sessions', function (Blueprint $table): void {
            $table->dropUnique(['applied_estimate_id']);
            $table->string('status', 50)->default('created')->change();
            $table->dropColumn([
                'state_version',
                'applied_at',
                'state_changed_at',
                'failure_code',
                'resume_status',
            ]);
        });
    }
};
