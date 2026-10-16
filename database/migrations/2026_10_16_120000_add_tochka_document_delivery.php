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
        Schema::table('commercial_bank_documents', function (Blueprint $table): void {
            $table->string('provider', 30)->default('legacy_local');
            $table->string('provider_document_id', 100)->nullable()->unique();
            $table->string('provider_customer_code', 100)->nullable();
            $table->jsonb('provider_request')->nullable();
            $table->timestampTz('creation_started_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable()->index();
            $table->string('failure_code', 80)->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
        });
        DB::table('commercial_bank_documents')->where('status', 'pending')->whereNull('storage_path')
            ->whereIn('kind', ['invoice', 'closing'])->update(['provider' => 'tochka']);
    }

    public function down(): void
    {
        Schema::table('commercial_bank_documents', function (Blueprint $table): void {
            $table->dropUnique(['provider_document_id']);
            $table->dropIndex(['next_attempt_at']);
            $table->dropColumn(['provider', 'provider_document_id', 'provider_customer_code', 'provider_request',
                'creation_started_at', 'next_attempt_at', 'failure_code', 'reviewed_by', 'review_note', 'reviewed_at']);
        });
    }
};
