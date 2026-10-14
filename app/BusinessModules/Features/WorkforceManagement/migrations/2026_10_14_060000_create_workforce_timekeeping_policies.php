<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('workforce_timekeeping_policies', function (Blueprint $table): void {
            $table->foreignId('organization_id')->primary()->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->jsonb('settings');
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('updated_at');
        });
    }
    public function down(): void { Schema::dropIfExists('workforce_timekeeping_policies'); }
};
