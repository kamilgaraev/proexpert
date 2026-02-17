<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->decimal('total_base_overhead_amount', 15, 2)->default(0)->after('total_base_labor_cost');
            $table->decimal('total_base_profit_amount', 15, 2)->default(0)->after('total_base_overhead_amount');
        });

        Schema::table('estimate_items', function (Blueprint $table) {
            $table->decimal('base_overhead_amount', 15, 2)->default(0)->after('base_labor_cost');
            $table->decimal('base_profit_amount', 15, 2)->default(0)->after('base_overhead_amount');
            
            // Adding rates if they don't exist yet (just in case, usually they are stored but maybe not explicitly)
            // Assuming overhead_rate and profit_rate columns might already exist or handled via virtual attributes
            // But let's add them explicitly to be safe if they are missing, checking first 
            // - actually schema builder doesn't support 'if not exists' easily for columns in sqlite/mysql same way
            // So I will assume they might exist.
            // Let's check via schema hasColumn in a separate block if needed.
            // But standard practice:
            if (!Schema::hasColumn('estimate_items', 'overhead_rate')) {
                $table->decimal('overhead_rate', 5, 2)->default(0)->after('machinery_cost');
            }
            if (!Schema::hasColumn('estimate_items', 'profit_rate')) {
                $table->decimal('profit_rate', 5, 2)->default(0)->after('overhead_rate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropColumn(['total_base_overhead_amount', 'total_base_profit_amount']);
        });

        Schema::table('estimate_items', function (Blueprint $table) {
            $table->dropColumn(['base_overhead_amount', 'base_profit_amount']);
             // We won't drop rates as they might have been there
        });
    }
};
