<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('completed_works', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('work_type_id')->constrained();
            $table->foreignId('user_id')->constrained()->comment('User who recorded the completed work');
            $table->decimal('quantity', 15, 3);
            $table->decimal('price', 15, 2)->nullable();
            $table->decimal('total_amount', 15, 2)->nullable();
            $table->date('completion_date');
            $table->text('notes')->nullable();
            $table->string('status')->default('confirmed'); // draft, confirmed, cancelled
            $table->json('additional_info')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('completed_works');
    }
};
