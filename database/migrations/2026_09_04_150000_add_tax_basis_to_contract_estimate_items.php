<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_estimate_items', function (Blueprint $table): void {
            $table->decimal('amount_without_vat', 15, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contract_estimate_items', function (Blueprint $table): void {
            $table->dropColumn('amount_without_vat');
        });
    }
};
