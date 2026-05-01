<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_parties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('status', 32);
            $table->foreignId('registered_supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('external_supplier_contact_id')->nullable()->constrained('external_supplier_contacts')->nullOnDelete();
            $table->string('display_name');
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('normalized_email')->nullable();
            $table->string('phone')->nullable();
            $table->string('tax_id')->nullable();
            $table->json('snapshot')->nullable()->default('{}');
            $table->timestamp('linked_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'type', 'status']);
            $table->unique(
                ['organization_id', 'type', 'registered_supplier_id'],
                'supplier_parties_org_type_registered_unique'
            );
            $table->unique(
                ['organization_id', 'type', 'external_supplier_contact_id'],
                'supplier_parties_org_type_external_unique'
            );
            $table->index(['organization_id', 'normalized_email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_parties');
    }
};
