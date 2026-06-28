<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('ai_generation_feedback');
        Schema::dropIfExists('ai_generation_history');
    }

    public function down(): void
    {
    }
};
