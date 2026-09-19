<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_document_obligations', function (Blueprint $table): void {
            $table->dropUnique('legal_document_obligations_document_title_unique');
            $table->string('source_key', 64)->nullable();
            $table->unique(['document_id', 'source_key'], 'legal_obligations_document_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('legal_document_obligations', function (Blueprint $table): void {
            $table->unique(['document_id', 'title'], 'legal_document_obligations_document_title_unique');
            $table->dropUnique('legal_obligations_document_source_unique');
            $table->dropColumn('source_key');
        });
    }
};
