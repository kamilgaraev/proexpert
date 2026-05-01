<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'purchase_orders_accepted_supplier_proposal_unique';

    public function up(): void
    {
        if (!Schema::hasColumn('purchase_orders', 'accepted_supplier_proposal_id')) {
            return;
        }

        $duplicate = DB::table('purchase_orders')
            ->select('accepted_supplier_proposal_id')
            ->whereNotNull('accepted_supplier_proposal_id')
            ->groupBy('accepted_supplier_proposal_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException(
                'Duplicate purchase orders for accepted supplier proposal must be resolved before adding unique guard.'
            );
        }

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->unique('accepted_supplier_proposal_id', self::INDEX_NAME);
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('purchase_orders', 'accepted_supplier_proposal_id')) {
            return;
        }

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropUnique(self::INDEX_NAME);
        });
    }
};
