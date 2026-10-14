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
        Schema::table('contract_revision_legal_bindings', function (Blueprint $table): void {
            $table->jsonb('document_requisites')->nullable();
        });
        DB::unprepared("ALTER TABLE contract_revision_legal_bindings ADD CONSTRAINT contract_revision_legal_requisites_object CHECK (document_requisites IS NULL OR jsonb_typeof(document_requisites) = 'object')");
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE contract_revision_legal_bindings DROP CONSTRAINT contract_revision_legal_requisites_object');
        Schema::table('contract_revision_legal_bindings', function (Blueprint $table): void {
            $table->dropColumn('document_requisites');
        });
    }
};
