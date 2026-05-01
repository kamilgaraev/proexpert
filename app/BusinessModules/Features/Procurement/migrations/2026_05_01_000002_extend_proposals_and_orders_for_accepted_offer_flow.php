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
        $this->makeSupplierNullable('supplier_proposals');
        $this->makeSupplierNullable('purchase_orders');

        Schema::table('supplier_proposals', function (Blueprint $table): void {
            if (!Schema::hasColumn('supplier_proposals', 'supplier_request_id')) {
                $table->foreignId('supplier_request_id')->nullable()->after('purchase_order_id')->constrained('supplier_requests')->cascadeOnDelete();
            }

            if (!Schema::hasColumn('supplier_proposals', 'external_supplier_contact_id')) {
                $table->foreignId('external_supplier_contact_id')->nullable()->after('supplier_id')->constrained('external_supplier_contacts')->nullOnDelete();
            }

            if (!Schema::hasColumn('supplier_proposals', 'subtotal_amount')) {
                $table->decimal('subtotal_amount', 15, 2)->default(0)->after('status');
            }

            if (!Schema::hasColumn('supplier_proposals', 'delivery_amount')) {
                $table->decimal('delivery_amount', 15, 2)->default(0)->after('subtotal_amount');
            }

            if (!Schema::hasColumn('supplier_proposals', 'vat_amount')) {
                $table->decimal('vat_amount', 15, 2)->default(0)->after('delivery_amount');
            }

            if (!Schema::hasColumn('supplier_proposals', 'payment_terms')) {
                $table->text('payment_terms')->nullable()->after('valid_until');
            }

            if (!Schema::hasColumn('supplier_proposals', 'delivery_terms')) {
                $table->text('delivery_terms')->nullable()->after('payment_terms');
            }
        });

        Schema::create('supplier_proposal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_proposal_id')->constrained('supplier_proposals')->cascadeOnDelete();
            $table->foreignId('supplier_request_line_id')->nullable()->constrained('supplier_request_lines')->nullOnDelete();
            $table->foreignId('material_id')->nullable()->constrained('materials')->nullOnDelete();
            $table->text('name');
            $table->decimal('quantity', 15, 3);
            $table->string('unit', 32);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('total_amount', 15, 2);
            $table->text('comment')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('supplier_proposal_id');
            $table->index('supplier_request_line_id');
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            if (!Schema::hasColumn('purchase_orders', 'accepted_supplier_proposal_id')) {
                $table->foreignId('accepted_supplier_proposal_id')->nullable()->after('purchase_request_id')->constrained('supplier_proposals')->nullOnDelete();
            }

            if (!Schema::hasColumn('purchase_orders', 'external_supplier_contact_id')) {
                $table->foreignId('external_supplier_contact_id')->nullable()->after('supplier_id')->constrained('external_supplier_contacts')->nullOnDelete();
            }

            if (!Schema::hasColumn('purchase_orders', 'pricing_source')) {
                $table->string('pricing_source', 64)->default('accepted_supplier_proposal')->after('currency');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            foreach (['pricing_source', 'external_supplier_contact_id', 'accepted_supplier_proposal_id'] as $column) {
                if (Schema::hasColumn('purchase_orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('supplier_proposal_lines');

        Schema::table('supplier_proposals', function (Blueprint $table): void {
            foreach ([
                'delivery_terms',
                'payment_terms',
                'vat_amount',
                'delivery_amount',
                'subtotal_amount',
                'external_supplier_contact_id',
                'supplier_request_id',
            ] as $column) {
                if (Schema::hasColumn('supplier_proposals', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function makeSupplierNullable(string $table): void
    {
        if (!Schema::hasColumn($table, 'supplier_id')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN supplier_id DROP NOT NULL");

            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->unsignedBigInteger('supplier_id')->nullable()->change();
        });
    }
};
