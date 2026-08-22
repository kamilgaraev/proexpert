<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'purchase_receipts_order_idempotency_unique';

    public function up(): void
    {
        Schema::table('purchase_receipts', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->nullable()->after('receipt_date');
            $table->unique(
                ['organization_id', 'purchase_order_id', 'idempotency_key'],
                self::UNIQUE_INDEX,
            );
        });
    }

    public function down(): void
    {
        Schema::table('purchase_receipts', function (Blueprint $table): void {
            $table->dropUnique(self::UNIQUE_INDEX);
            $table->dropColumn('idempotency_key');
        });
    }
};
