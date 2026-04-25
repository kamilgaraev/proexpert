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
        Schema::create('acting_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->cascadeOnDelete();
            $table->string('mode', 32)->default('operational');
            $table->boolean('allow_manual_lines')->default(false);
            $table->boolean('require_manual_line_reason')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'contract_id'], 'acting_policies_org_contract_unique');
            $table->index(['organization_id', 'mode'], 'acting_policies_org_mode_idx');
        });

        DB::statement(
            'CREATE UNIQUE INDEX acting_policies_org_default_unique ON acting_policies (organization_id) WHERE contract_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('acting_policies');
    }
};
