<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_coefficients', function (Blueprint $table): void {
            $table->dropUnique(['code']);
            $table->unique(['organization_id', 'code'], 'rate_coefficients_organization_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('rate_coefficients', function (Blueprint $table): void {
            $table->dropUnique('rate_coefficients_organization_code_unique');
            $table->unique('code');
        });
    }
};
