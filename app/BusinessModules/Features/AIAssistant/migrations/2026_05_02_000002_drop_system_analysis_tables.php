<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('system_analysis_history');
        Schema::dropIfExists('system_analysis_sections');
        Schema::dropIfExists('system_analysis_reports');
    }

    public function down(): void
    {
    }
};
