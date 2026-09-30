<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_assistant_requests', function (Blueprint $table): void {
            $table->jsonb('payload')->nullable();
            $table->string('surface', 12)->nullable();
            $table->timestamp('started_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_assistant_requests', function (Blueprint $table): void {
            $table->dropColumn(['payload', 'surface', 'started_at']);
        });
    }
};
