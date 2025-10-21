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
        // Удаляем старый constraint
        DB::statement('ALTER TABLE contractors DROP CONSTRAINT IF EXISTS contractors_contractor_type_check');
        
        // Добавляем новый constraint с holding_member
        DB::statement("
            ALTER TABLE contractors 
            ADD CONSTRAINT contractors_contractor_type_check 
            CHECK (contractor_type IN ('manual', 'invited_organization', 'holding_member'))
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Удаляем constraint с holding_member
        DB::statement('ALTER TABLE contractors DROP CONSTRAINT IF EXISTS contractors_contractor_type_check');
        
        // Возвращаем старый constraint без holding_member
        DB::statement("
            ALTER TABLE contractors 
            ADD CONSTRAINT contractors_contractor_type_check 
            CHECK (contractor_type IN ('manual', 'invited_organization'))
        ");
    }
};
