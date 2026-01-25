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
        Schema::create('estimate_item_works', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_item_id')->constrained()->onDelete('cascade');
            
            $table->string('caption');
            $table->integer('sort_order')->default(0);
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            $table->index(['estimate_item_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estimate_item_works');
    }
};
