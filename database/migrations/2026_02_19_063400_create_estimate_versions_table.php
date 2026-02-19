<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedSmallInteger('version_number')->default(1);
            $table->string('label')->nullable();
            $table->text('comment')->nullable();

            $table->json('snapshot')->comment('Полный снапшот структуры сметы на момент версии');

            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('total_amount_with_vat', 15, 2)->default(0);
            $table->decimal('total_direct_costs', 15, 2)->default(0);

            $table->timestamps();

            $table->unique(['estimate_id', 'version_number']);
            $table->index(['estimate_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_versions');
    }
};
