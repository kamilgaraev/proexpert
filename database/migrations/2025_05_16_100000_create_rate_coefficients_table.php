<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Enums\RateCoefficient\RateCoefficientTypeEnum;
use App\Enums\RateCoefficient\RateCoefficientAppliesToEnum;
use App\Enums\RateCoefficient\RateCoefficientScopeEnum;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('rate_coefficients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            
            $table->string('name');
            $table->string('code')->unique()->nullable();
            $table->decimal('value', 10, 4); // Например, 1.1500 (для процентов) или 100.00 (для фикс. суммы)
            
            $table->enum('type', RateCoefficientTypeEnum::values());
            $table->enum('applies_to', RateCoefficientAppliesToEnum::values());
            $table->enum('scope', RateCoefficientScopeEnum::values());
            
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            
            $table->json('conditions')->nullable(); // Для доп. условий, например {"project_ids": [1,2], "min_amount": 10000}
            
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
            $table->index('is_active');
            $table->index(['organization_id', 'is_active', 'scope', 'applies_to'], 'idx_rate_coeff_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rate_coefficients');
    }
}; 