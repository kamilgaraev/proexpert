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
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE contractors DROP CONSTRAINT IF EXISTS contractors_contractor_type_check');
        
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
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE contractors DROP CONSTRAINT IF EXISTS contractors_contractor_type_check');
        
        DB::statement("
            ALTER TABLE contractors 
            ADD CONSTRAINT contractors_contractor_type_check 
            CHECK (contractor_type IN ('manual', 'invited_organization'))
        ");
    }
};
