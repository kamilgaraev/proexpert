<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holding_sites', function (Blueprint $table) {
            $table->string('domain')->nullable()->change();
            $table->foreignId('created_by_user_id')->nullable()->change();
        });

        if (Schema::hasColumn('holding_sites', 'template_id')) {
            Schema::table('holding_sites', function (Blueprint $table) {
                $table->dropColumn('template_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('holding_sites', function (Blueprint $table) {
            $table->string('domain')->nullable(false)->change();
            $table->foreignId('created_by_user_id')->nullable(false)->change();
        });
        
        if (!Schema::hasColumn('holding_sites', 'template_id')) {
            Schema::table('holding_sites', function (Blueprint $table) {
                $table->string('template_id')->default('default')->after('favicon_url');
            });
        }
    }
};

