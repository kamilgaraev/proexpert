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
            // Делаем user_id nullable
            $table->unsignedBigInteger('user_id')->nullable()->change();
            
            // Добавляем поле для имени получателя (если это не пользователь системы)
            $table->string('recipient_name')->nullable()->after('user_id')->comment('Имя получателя (если не указан user_id)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('advance_account_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->dropColumn('recipient_name');
        });
    }
};
