<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->integer('duration_in_days')->default(30)->after('currency');
            $table->integer('max_foremen')->nullable()->after('trial_days');
            $table->integer('max_projects')->nullable()->after('max_foremen');
            $table->integer('max_storage_gb')->nullable()->after('max_projects');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn([
                'duration_in_days',
                'max_foremen', 
                'max_projects',
                'max_storage_gb'
            ]);
        });
    }
};
