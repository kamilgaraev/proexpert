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
            // Пытаемся удалить индекс, если существует (может не существовать в некоторых БД)
            try {
                $table->dropIndex(['status']);
            } catch (\Exception $e) {
                // Индекс может не существовать или иметь другое имя, игнорируем
            }
            
            $table->dropColumn('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplementary_agreements', function (Blueprint $table) {
            $table->string('status', 50)->default('draft')->after('agreement_date');
            $table->index('status');
        });
    }
};
