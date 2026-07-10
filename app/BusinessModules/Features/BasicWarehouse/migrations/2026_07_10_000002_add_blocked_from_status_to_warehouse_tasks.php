<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_tasks', function (Blueprint $table): void {
            $table->string('blocked_from_status', 30)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_tasks', function (Blueprint $table): void {
            $table->dropColumn('blocked_from_status');
        });
    }
};
