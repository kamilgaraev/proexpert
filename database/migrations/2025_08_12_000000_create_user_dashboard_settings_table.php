<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_dashboard_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            // Для мультиарендности используем not null с default 0, чтобы обеспечить уникальный индекс
            $table->unsignedBigInteger('organization_id')->default(0);
            $table->unsignedInteger('version');
            $table->string('layout_mode', 50)->default('simple');
            $table->json('items');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'organization_id']);
            $table->unique(['user_id', 'organization_id'], 'uniq_user_org_dashboard_settings');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_dashboard_settings');
    }
};


