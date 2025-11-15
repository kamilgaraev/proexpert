<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('organization_module_activations', function (Blueprint $table) {
            $table->boolean('is_auto_renew_enabled')
                ->default(true)
                ->after('cancelled_at')
                ->comment('Автоматическое продление модуля');
            
            $table->index('is_auto_renew_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organization_module_activations', function (Blueprint $table) {
            $table->dropIndex(['is_auto_renew_enabled']);
            $table->dropColumn('is_auto_renew_enabled');
        });
    }
};
