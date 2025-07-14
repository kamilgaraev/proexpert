<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_specification', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('specification_id')->constrained()->cascadeOnDelete();
            $table->timestamp('attached_at')->nullable();
            $table->timestamps();

            $table->unique(['contract_id', 'specification_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_specification');
    }
}; 