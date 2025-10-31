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
            $table->json('supersede_agreement_ids')->nullable()->after('new_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplementary_agreements', function (Blueprint $table) {
            $table->dropColumn('supersede_agreement_ids');
        });
    }
};
