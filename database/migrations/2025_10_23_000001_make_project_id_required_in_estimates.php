<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DELETE FROM estimates WHERE project_id IS NULL');
        
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });
        
        DB::statement('ALTER TABLE estimates ALTER COLUMN project_id SET NOT NULL');
        
        Schema::table('estimates', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });
        
        DB::statement('ALTER TABLE estimates ALTER COLUMN project_id DROP NOT NULL');
        
        Schema::table('estimates', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('set null');
        });
    }
};

