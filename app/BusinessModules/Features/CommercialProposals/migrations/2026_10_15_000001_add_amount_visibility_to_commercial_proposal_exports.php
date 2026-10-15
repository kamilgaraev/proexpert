<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_proposal_exports', static function (Blueprint $table): void {
            $table->boolean('amounts_visible')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('commercial_proposal_exports', static function (Blueprint $table): void {
            $table->dropColumn('amounts_visible');
        });
    }
};
