<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_requests', function (Blueprint $table): void {
            if (!Schema::hasColumn('supplier_requests', 'public_token')) {
                $table->string('public_token', 96)->nullable()->unique()->after('request_number');
            }

            if (!Schema::hasColumn('supplier_requests', 'public_token_expires_at')) {
                $table->timestamp('public_token_expires_at')->nullable()->after('sent_at');
            }

            if (!Schema::hasColumn('supplier_requests', 'public_opened_at')) {
                $table->timestamp('public_opened_at')->nullable()->after('public_token_expires_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('supplier_requests', function (Blueprint $table): void {
            foreach (['public_opened_at', 'public_token_expires_at', 'public_token'] as $column) {
                if (Schema::hasColumn('supplier_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
