<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_orders', function (Blueprint $table): void {
            $table->jsonb('selected_resource_addons')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('commercial_orders', function (Blueprint $table): void {
            $table->dropColumn('selected_resource_addons');
        });
    }
};
