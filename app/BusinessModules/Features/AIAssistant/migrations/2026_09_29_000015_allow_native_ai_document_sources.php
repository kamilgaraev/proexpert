<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ai_assistant_documents', static function (Blueprint $table): void {
            $table->foreignId('file_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('ai_assistant_documents')->whereNull('file_id')->exists()) {
            throw new RuntimeException('native_ai_documents_prevent_file_id_rollback');
        }

        Schema::table('ai_assistant_documents', static function (Blueprint $table): void {
            $table->foreignId('file_id')->nullable(false)->change();
        });
    }
};
