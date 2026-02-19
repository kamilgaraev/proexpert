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
        // Drop old check constraint and add new one allowing 'machinery'
        DB::statement('ALTER TABLE estimate_items DROP CONSTRAINT IF EXISTS estimate_items_item_type_check');
        DB::statement("ALTER TABLE estimate_items ADD CONSTRAINT estimate_items_item_type_check CHECK (item_type::text = ANY (ARRAY['work'::character varying, 'material'::character varying, 'equipment'::character varying, 'labor'::character varying, 'summary'::character varying, 'machinery'::character varying]::text[]))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to old CHECK constraint without 'machinery'
        // First we have to update any existing 'machinery' items to 'equipment' so we don't break the constraint
        DB::table('estimate_items')->where('item_type', 'machinery')->update(['item_type' => 'equipment']);
        
        DB::statement('ALTER TABLE estimate_items DROP CONSTRAINT IF EXISTS estimate_items_item_type_check');
        DB::statement("ALTER TABLE estimate_items ADD CONSTRAINT estimate_items_item_type_check CHECK (item_type::text = ANY (ARRAY['work'::character varying, 'material'::character varying, 'equipment'::character varying, 'labor'::character varying, 'summary'::character varying]::text[]))");
    }
};
