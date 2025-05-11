<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('work_completion_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('work_completion_logs', 'organization_id')) {
                $table->foreignId('organization_id')->after('user_id')->nullable()->constrained('organizations')->onDelete('cascade');
            }
            if (!Schema::hasColumn('work_completion_logs', 'unit_price')) {
                $table->decimal('unit_price', 10, 2)->nullable()->after('quantity');
            }
            if (!Schema::hasColumn('work_completion_logs', 'total_price')) {
                $table->decimal('total_price', 12, 2)->nullable()->after('unit_price');
            }
            if (!Schema::hasColumn('work_completion_logs', 'performers_description')) {
                $table->text('performers_description')->nullable()->after('completion_date');
            }
            if (!Schema::hasColumn('work_completion_logs', 'photo_path')) {
                $table->string('photo_path')->nullable()->after('performers_description');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_completion_logs', function (Blueprint $table) {
            if (Schema::hasColumn('work_completion_logs', 'photo_path')) {
                $table->dropColumn('photo_path');
            }
            if (Schema::hasColumn('work_completion_logs', 'performers_description')) {
                $table->dropColumn('performers_description');
            }
            if (Schema::hasColumn('work_completion_logs', 'total_price')) {
                $table->dropColumn('total_price');
            }
            if (Schema::hasColumn('work_completion_logs', 'unit_price')) {
                $table->dropColumn('unit_price');
            }
            if (Schema::hasColumn('work_completion_logs', 'organization_id')) {
                 if (DB::getDriverName() !== 'sqlite') {
                    try { $table->dropForeign(['organization_id']); } catch (\Exception $e) { /* Игнорируем */ }
                }
                $table->dropColumn('organization_id');
            }
        });
    }
}; 