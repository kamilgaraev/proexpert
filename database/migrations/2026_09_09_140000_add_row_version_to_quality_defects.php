<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quality_defects', static function (Blueprint $table): void {
            $table->unsignedBigInteger('row_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('quality_defects', static function (Blueprint $table): void {
            $table->dropColumn('row_version');
        });
    }
};
