<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('construction_journal_entries', function (Blueprint $table): void {
            $table->string('idempotency_key', 100)->nullable()->after('entry_number');
            $table->char('payload_fingerprint', 64)->nullable()->after('idempotency_key');
            $table->unique(
                ['journal_id', 'created_by_user_id', 'idempotency_key'],
                'journal_entries_idempotency_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('construction_journal_entries', function (Blueprint $table): void {
            $table->dropUnique('journal_entries_idempotency_unique');
            $table->dropColumn(['idempotency_key', 'payload_fingerprint']);
        });
    }
};
