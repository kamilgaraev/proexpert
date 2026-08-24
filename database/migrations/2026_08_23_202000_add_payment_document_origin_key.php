<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_documents', function (Blueprint $table): void {
            $table->string('origin_key', 191)->nullable();
            $table->unique(['organization_id', 'origin_key'], 'payment_documents_org_origin_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payment_documents', function (Blueprint $table): void {
            $table->dropUnique('payment_documents_org_origin_unique');
            $table->dropColumn('origin_key');
        });
    }
};
