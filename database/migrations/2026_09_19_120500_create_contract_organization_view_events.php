<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_organization_view_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('view_id')->constrained('contract_organization_views')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('action', 16);
            $table->string('from_visibility', 16);
            $table->string('to_visibility', 16);
            $table->unsignedInteger('version');
            $table->timestampTz('created_at');
            $table->unique(['view_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_organization_view_events');
    }
};
