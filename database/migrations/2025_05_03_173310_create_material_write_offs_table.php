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
        Schema::dropIfExists('material_write_offs');
    }
};
