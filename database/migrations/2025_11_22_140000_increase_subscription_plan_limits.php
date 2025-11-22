<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Start plan
        DB::table('subscription_plans')
            ->where('slug', 'start')
            ->update([
                'max_users' => 10, // Было 5
                'max_foremen' => 3 // Было 2
            ]);

        // Business plan
        DB::table('subscription_plans')
            ->where('slug', 'business')
            ->update([
                'max_users' => 30, // Было 15
                'max_foremen' => 15 // Было 10
            ]);

        // Profi plan
        DB::table('subscription_plans')
            ->where('slug', 'profi')
            ->update([
                'max_users' => 100, // Было 50
                'max_foremen' => 40 // Было 30
            ]);
            
        // Обновим описания features в JSON
        // Это сложнее сделать чистым SQL для JSON, поэтому сделаем через перебор
        // Но поскольку это миграция, лучше сделать просто апдейт полей, а features оставить как есть
        // или обновить их полностью если нужно.
        // В данном случае мы просто увеличиваем технические лимиты.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Start plan
        DB::table('subscription_plans')
            ->where('slug', 'start')
            ->update([
                'max_users' => 5,
                'max_foremen' => 2
            ]);

        // Business plan
        DB::table('subscription_plans')
            ->where('slug', 'business')
            ->update([
                'max_users' => 15,
                'max_foremen' => 10
            ]);

        // Profi plan
        DB::table('subscription_plans')
            ->where('slug', 'profi')
            ->update([
                'max_users' => 50,
                'max_foremen' => 30
            ]);
    }
};

