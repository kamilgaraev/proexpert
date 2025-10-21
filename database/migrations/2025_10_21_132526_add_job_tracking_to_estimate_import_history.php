<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_import_history', function (Blueprint $table) {
            $table->string('job_id')->nullable()->after('id')->index();
            $table->integer('progress')->default(0)->after('status');
            $table->enum('status', ['queued', 'processing', 'completed', 'failed', 'partial'])->default('processing')->change();
        });
    }

    public function down(): void
    {
        Schema::table('estimate_import_history', function (Blueprint $table) {
            $table->dropColumn(['job_id', 'progress']);
            $table->enum('status', ['processing', 'completed', 'failed', 'partial'])->default('processing')->change();
        });
    }
};
