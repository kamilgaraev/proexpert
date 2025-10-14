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
        Schema::table('supplementary_agreements', function (Blueprint $table) {
            $table->jsonb('advance_changes')->nullable()->after('change_amount');
            $table->jsonb('subcontract_changes')->nullable()->after('advance_changes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplementary_agreements', function (Blueprint $table) {
            $table->dropColumn(['advance_changes', 'subcontract_changes']);
        });
    }
};
