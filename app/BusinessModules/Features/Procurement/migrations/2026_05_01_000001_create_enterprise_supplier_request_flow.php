<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table): void {
            if (!Schema::hasColumn('purchase_requests', 'needed_by')) {
                $table->date('needed_by')->nullable()->after('status');
            }

            if (!Schema::hasColumn('purchase_requests', 'budget_amount')) {
                $table->decimal('budget_amount', 15, 2)->nullable()->after('needed_by');
            }

            if (!Schema::hasColumn('purchase_requests', 'budget_currency')) {
                $table->string('budget_currency', 3)->default('RUB')->after('budget_amount');
            }
        });

        Schema::create('purchase_request_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained('purchase_requests')->cascadeOnDelete();
            $table->foreignId('material_id')->nullable()->constrained('materials')->nullOnDelete();
            $table->text('name');
            $table->decimal('quantity', 15, 3);
            $table->string('unit', 32);
            $table->text('specification')->nullable();
            $table->date('needed_by')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('purchase_request_id');
            $table->index('material_id');
        });

        Schema::create('external_supplier_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('tax_number')->nullable();
            $table->text('address')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'name']);
            $table->index(['organization_id', 'tax_number']);
        });

        Schema::create('supplier_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('purchase_request_id')->constrained('purchase_requests')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('external_supplier_contact_id')->nullable()->constrained('external_supplier_contacts')->nullOnDelete();
            $table->string('request_number')->unique();
            $table->string('status', 50)->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('comment')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'supplier_id']);
            $table->index(['organization_id', 'external_supplier_contact_id']);
            $table->index('purchase_request_id');
        });

        Schema::create('supplier_request_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_request_id')->constrained('supplier_requests')->cascadeOnDelete();
            $table->foreignId('purchase_request_line_id')->nullable()->constrained('purchase_request_lines')->nullOnDelete();
            $table->foreignId('material_id')->nullable()->constrained('materials')->nullOnDelete();
            $table->text('name');
            $table->decimal('quantity', 15, 3);
            $table->string('unit', 32);
            $table->text('specification')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('supplier_request_id');
            $table->index('purchase_request_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_request_lines');
        Schema::dropIfExists('supplier_requests');
        Schema::dropIfExists('external_supplier_contacts');
        Schema::dropIfExists('purchase_request_lines');

        Schema::table('purchase_requests', function (Blueprint $table): void {
            foreach (['budget_currency', 'budget_amount', 'needed_by'] as $column) {
                if (Schema::hasColumn('purchase_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
