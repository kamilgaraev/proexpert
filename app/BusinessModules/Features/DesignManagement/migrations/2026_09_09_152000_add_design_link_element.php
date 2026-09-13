<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_source_links', function (Blueprint $table): void {
            $table->unsignedBigInteger('source_element_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('design_source_links', function (Blueprint $table): void {
            $table->dropColumn('source_element_id');
        });
    }
};
