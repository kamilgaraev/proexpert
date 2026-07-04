<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->foreignId('customer_counterparty_id')
                ->nullable()
                ->after('customer')
                ->constrained('counterparties')
                ->nullOnDelete();

            $table->index(['organization_id', 'customer_counterparty_id']);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'customer_counterparty_id']);
            $table->dropConstrainedForeignId('customer_counterparty_id');
        });
    }
};
