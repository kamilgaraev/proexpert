<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('completed_works', 'deadline')) {
            Schema::table('completed_works', function (Blueprint $table) {
                $table->timestamp('deadline')->nullable()->after('created_at');
                $table->timestamp('completed_at')->nullable()->after('deadline');
                $table->decimal('quality_rating', 3, 2)->nullable()->after('completed_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('completed_works', 'deadline')) {
            Schema::table('completed_works', function (Blueprint $table) {
                $table->dropColumn(['deadline', 'completed_at', 'quality_rating']);
            });
        }
    }
};

