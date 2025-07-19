<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_admin_role', function(Blueprint $table) {
            $table->id();
            $table->foreignId('landing_admin_id')->constrained('landing_admins')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['landing_admin_id','role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_admin_role');
    }
}; 