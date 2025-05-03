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
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->morphs('fileable'); // Полиморфная связь для привязки к разным моделям
            $table->foreignId('user_id')->constrained()->comment('User who uploaded the file');
            $table->string('name');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type');
            $table->bigInteger('size');
            $table->string('disk')->default('local');
            $table->string('type')->nullable(); // document, photo, etc.
            $table->string('category')->nullable(); // receipt, write-off, completed-work, etc.
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
        Schema::dropIfExists('files');
    }
};
