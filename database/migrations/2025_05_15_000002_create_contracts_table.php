<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractTypeEnum;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('set null');
            $table->foreignId('contractor_id')->constrained('contractors')->onDelete('restrict');
            $table->foreignId('parent_contract_id')->nullable()->constrained('contracts')->onDelete('cascade');

            $table->string('number');
            $table->date('date');
            $table->string('type')->default(ContractTypeEnum::CONTRACT->value);
            $table->text('subject')->nullable();
            $table->string('work_type_category')->nullable();
            $table->text('payment_terms')->nullable();
            $table->decimal('total_amount', 15, 2);
            $table->decimal('gp_percentage', 5, 2)->nullable()->default(0);
            $table->decimal('planned_advance_amount', 15, 2)->nullable()->default(0);
            $table->string('status')->default(ContractStatusEnum::DRAFT->value);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('notes')->nullable();
            
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'contractor_id']);
            $table->index(['organization_id', 'project_id']);
            $table->index(['organization_id', 'status']);
            $table->index('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
}; 