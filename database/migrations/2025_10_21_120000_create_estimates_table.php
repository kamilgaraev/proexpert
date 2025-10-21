<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('contract_id')->nullable()->constrained()->onDelete('set null');
            
            $table->string('number')->index();
            $table->string('name');
            $table->text('description')->nullable();
            
            $table->enum('type', ['local', 'object', 'summary', 'contractual'])->default('local');
            $table->enum('status', ['draft', 'in_review', 'approved', 'cancelled'])->default('draft');
            
            $table->integer('version')->default(1);
            $table->foreignId('parent_estimate_id')->nullable()->constrained('estimates')->onDelete('set null');
            
            $table->date('estimate_date');
            $table->date('base_price_date')->nullable();
            
            $table->decimal('total_direct_costs', 15, 2)->default(0);
            $table->decimal('total_overhead_costs', 15, 2)->default(0);
            $table->decimal('total_estimated_profit', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('total_amount_with_vat', 15, 2)->default(0);
            
            $table->decimal('vat_rate', 5, 2)->default(20);
            $table->decimal('overhead_rate', 5, 2)->default(15);
            $table->decimal('profit_rate', 5, 2)->default(12);
            
            $table->enum('calculation_method', ['base_index', 'resource', 'resource_index', 'analog'])->default('resource');
            
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'project_id']);
            $table->index(['organization_id', 'contract_id']);
            $table->unique(['organization_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimates');
    }
};

