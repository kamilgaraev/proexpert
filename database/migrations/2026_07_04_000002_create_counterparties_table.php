<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counterparties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('linked_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('inn', 12)->nullable();
            $table->string('kpp', 9)->nullable();
            $table->string('ogrn', 15)->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('contact_person')->nullable();
            $table->text('legal_address')->nullable();
            $table->text('postal_address')->nullable();
            $table->jsonb('bank_details')->nullable();
            $table->jsonb('roles')->nullable();
            $table->string('source')->default('manual');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_active']);
            $table->index(['organization_id', 'linked_organization_id']);
            $table->index(['organization_id', 'name']);
            $table->index(['organization_id', 'inn']);
        });

        DB::statement(
            "CREATE UNIQUE INDEX counterparties_org_inn_kpp_unique_active
            ON counterparties (organization_id, inn, COALESCE(kpp, ''))
            WHERE deleted_at IS NULL AND inn IS NOT NULL AND inn <> ''"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS counterparties_org_inn_kpp_unique_active');

        Schema::dropIfExists('counterparties');
    }
};
