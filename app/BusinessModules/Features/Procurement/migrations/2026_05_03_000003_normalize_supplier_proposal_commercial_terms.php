<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_proposals', function (Blueprint $table): void {
            if (!Schema::hasColumn('supplier_proposals', 'vat_mode')) {
                $table->string('vat_mode', 32)->default('included')->after('currency');
            }

            if (!Schema::hasColumn('supplier_proposals', 'vat_rate')) {
                $table->decimal('vat_rate', 5, 2)->nullable()->after('vat_mode');
            }

            if (!Schema::hasColumn('supplier_proposals', 'warranty_terms')) {
                $table->text('warranty_terms')->nullable()->after('delivery_terms');
            }

            if (!Schema::hasColumn('supplier_proposals', 'delivery_due_date')) {
                $table->date('delivery_due_date')->nullable()->after('valid_until');
            }

            if (!Schema::hasColumn('supplier_proposals', 'lead_time_days')) {
                $table->unsignedInteger('lead_time_days')->nullable()->after('delivery_due_date');
            }

            $table->index(['organization_id', 'currency'], 'supplier_proposals_org_currency_index');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_proposals', function (Blueprint $table): void {
            if (Schema::hasColumn('supplier_proposals', 'currency')) {
                $table->dropIndex('supplier_proposals_org_currency_index');
            }

            foreach (['lead_time_days', 'delivery_due_date', 'warranty_terms', 'vat_rate', 'vat_mode'] as $column) {
                if (Schema::hasColumn('supplier_proposals', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
