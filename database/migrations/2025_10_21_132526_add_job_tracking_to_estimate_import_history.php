<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_import_history', function (Blueprint $table) {
            $table->string('job_id')->nullable()->after('id')->index();
            $table->integer('progress')->default(0)->after('status');
        });
        
        DB::statement("ALTER TABLE estimate_import_history DROP CONSTRAINT IF EXISTS estimate_import_history_status_check");
        DB::statement("ALTER TABLE estimate_import_history ADD CONSTRAINT estimate_import_history_status_check CHECK (status IN ('queued', 'processing', 'completed', 'failed', 'partial'))");
    }

    public function down(): void
    {
        Schema::table('estimate_import_history', function (Blueprint $table) {
            $table->dropColumn(['job_id', 'progress']);
        });
        
        DB::statement("ALTER TABLE estimate_import_history DROP CONSTRAINT IF EXISTS estimate_import_history_status_check");
        DB::statement("ALTER TABLE estimate_import_history ADD CONSTRAINT estimate_import_history_status_check CHECK (status IN ('processing', 'completed', 'failed', 'partial'))");
    }
};
