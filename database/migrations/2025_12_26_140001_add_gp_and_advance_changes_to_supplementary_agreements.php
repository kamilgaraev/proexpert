<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplementary_agreements', function (Blueprint $table) {
            $table->jsonb('gp_changes')->nullable()->after('subcontract_changes');
            $table->jsonb('advance_changes')->nullable()->after('gp_changes');
        });
    }

    public function down(): void
    {
        Schema::table('supplementary_agreements', function (Blueprint $table) {
            $table->dropColumn(['gp_changes', 'advance_changes']);
        });
    }
};

