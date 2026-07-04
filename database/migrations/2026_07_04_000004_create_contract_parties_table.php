<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_parties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->string('side', 16);
            $table->string('role', 64);
            $table->foreignId('counterparty_id')->nullable()->constrained('counterparties')->nullOnDelete();
            $table->foreignId('linked_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('inn', 12)->nullable();
            $table->string('kpp', 9)->nullable();
            $table->string('ogrn', 15)->nullable();
            $table->text('legal_address')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->jsonb('snapshot');
            $table->timestamps();

            $table->unique(['contract_id', 'side']);
            $table->index(['counterparty_id']);
            $table->index(['linked_organization_id']);
            $table->index(['role']);
            $table->index(['inn']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_parties');
    }
};
