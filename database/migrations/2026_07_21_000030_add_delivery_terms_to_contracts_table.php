<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('contracts', 'delivery_terms')) {
            return;
        }

        Schema::table('contracts', function (Blueprint $table): void {
            $table->text('delivery_terms')->nullable()->after('payment_terms');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('contracts', 'delivery_terms')) {
            return;
        }

        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropColumn('delivery_terms');
        });
    }
};
