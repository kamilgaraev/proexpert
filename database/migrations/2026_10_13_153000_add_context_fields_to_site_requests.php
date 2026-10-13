<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['site_requests', 'site_request_groups'] as $table) {
            Schema::table($table, static function (Blueprint $table): void {
                $table->text('location')->nullable();
                $table->decimal('estimated_cost', 14, 2)->nullable();
                $table->text('requester_comment')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['site_requests', 'site_request_groups'] as $table) {
            Schema::table($table, static function (Blueprint $table): void {
                $table->dropColumn(['location', 'estimated_cost', 'requester_comment']);
            });
        }
    }
};
