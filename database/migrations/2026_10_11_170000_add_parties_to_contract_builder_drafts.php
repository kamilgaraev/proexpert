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
        Schema::table('contract_builder_drafts', static function (Blueprint $table): void { $table->jsonb('parties')->nullable(); });
    }

    public function down(): void
    {
        if (DB::table('contract_builder_drafts')->whereNotNull('parties')->exists()) throw new RuntimeException('contract_draft_parties_require_preservation');
        Schema::table('contract_builder_drafts', static function (Blueprint $table): void { $table->dropColumn('parties'); });
    }
};
