<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('estimate_import_ai_cache')) {
            Schema::create('estimate_import_ai_cache', function (Blueprint $table) {
                $table->id();
                // Hash ensures fast lookup: md5(lower(name) + '|' + lower(unit))
                $table->string('hash', 32)->unique()->index();
                
                $table->text('input_name');
                $table->string('input_unit')->nullable();
                
                // Classification result
                $table->string('result_type', 50); // work, material, equipment, etc.
                $table->float('confidence')->default(0.0);
                $table->string('source', 50)->default('ai_yandex');
                
                $table->timestamps();
                
                // Additional index for cleanup or analytics
                $table->index('updated_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_import_ai_cache');
    }
};
