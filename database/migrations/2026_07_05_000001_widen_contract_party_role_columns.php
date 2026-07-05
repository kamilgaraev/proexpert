<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contract_parties')) {
            return;
        }

        Schema::table('contract_parties', function (Blueprint $table): void {
            $table->string('side', 16)->change();
            $table->string('role', 64)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('contract_parties')) {
            return;
        }

        Schema::table('contract_parties', function (Blueprint $table): void {
            $table->string('side', 9)->change();
            $table->string('role', 9)->change();
        });
    }
};
