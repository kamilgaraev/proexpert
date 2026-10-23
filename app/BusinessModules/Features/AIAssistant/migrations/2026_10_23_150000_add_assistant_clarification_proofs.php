<?php

declare(strict_types=1);

use App\Contracts\Database\ForwardOnlyMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration implements ForwardOnlyMigration
{
    public function up(): void
    {
        Schema::table('ai_assistant_requests', static function (Blueprint $table): void {
            $table->jsonb('clarification_receipt')->nullable();
        });
        Schema::table('ai_assistant_egress_enrollments', static function (Blueprint $table): void {
            $table->jsonb('input_context')->nullable();
        });
    }

    public function down(): void {}
};
