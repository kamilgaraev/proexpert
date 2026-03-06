<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('contracts')
            ->whereNull('warranty_retention_calculation_type')
            ->update([
                'warranty_retention_percentage' => null,
                'warranty_retention_coefficient' => null,
            ]);
    }

    public function down(): void
    {
        DB::table('contracts')
            ->whereNull('warranty_retention_calculation_type')
            ->whereNull('warranty_retention_percentage')
            ->update([
                'warranty_retention_percentage' => 2.5,
            ]);
    }
};
