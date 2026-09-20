<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_template_card_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('request_key', 191);
            $table->char('fingerprint', 64);
            $table->foreignId('contract_id')->constrained('contracts')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->unique(['organization_id', 'request_key'], 'contract_template_card_operation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_template_card_operations');
    }
};
