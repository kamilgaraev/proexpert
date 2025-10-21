<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('material_write_offs');
        Schema::dropIfExists('material_receipts');
    }

    public function down(): void
    {
        Schema::create('material_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('material_id')->constrained();
            $table->foreignId('user_id')->constrained()->comment('User who created the receipt');
            $table->decimal('quantity', 15, 3);
            $table->decimal('price', 15, 2)->nullable();
            $table->decimal('total_amount', 15, 2)->nullable();
            $table->string('document_number')->nullable();
            $table->date('receipt_date');
            $table->text('notes')->nullable();
            $table->string('status')->default('confirmed');
            $table->json('additional_info')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('material_write_offs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('material_id')->constrained();
            $table->foreignId('work_type_id')->nullable()->constrained();
            $table->foreignId('user_id')->constrained()->comment('User who created the write-off');
            $table->decimal('quantity', 15, 3);
            $table->date('write_off_date');
            $table->text('notes')->nullable();
            $table->string('status')->default('confirmed');
            $table->json('additional_info')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
