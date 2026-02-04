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
        Schema::table('advance_account_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('cost_category_id')->nullable()->after('attachment_ids')->comment('ID категории затрат');
            $table->foreign('cost_category_id')->references('id')->on('cost_categories')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('advance_account_transactions', function (Blueprint $table) {
            $table->dropForeign(['cost_category_id']);
            $table->dropColumn('cost_category_id');
        });
    }
};
