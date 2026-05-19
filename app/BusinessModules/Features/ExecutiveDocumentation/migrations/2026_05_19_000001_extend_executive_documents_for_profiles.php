<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('executive_documents', function (Blueprint $table): void {
            if (!Schema::hasColumn('executive_documents', 'work_type_id')) {
                $table->foreignId('work_type_id')->nullable()->after('work_type_name')->constrained('work_types')->nullOnDelete();
            }

            if (!Schema::hasColumn('executive_documents', 'document_date')) {
                $table->date('document_date')->nullable()->after('completed_work_id');
            }

            if (!Schema::hasColumn('executive_documents', 'copies_count')) {
                $table->unsignedSmallInteger('copies_count')->nullable()->after('document_date');
            }

            if (!Schema::hasColumn('executive_documents', 'form_variant')) {
                $table->string('form_variant', 40)->nullable()->after('copies_count');
            }

            if (!Schema::hasColumn('executive_documents', 'journal_entry_id')) {
                $table->foreignId('journal_entry_id')->nullable()->after('form_variant')->constrained('construction_journal_entries')->nullOnDelete();
            }

            if (!Schema::hasColumn('executive_documents', 'profile_data')) {
                $table->jsonb('profile_data')->nullable()->after('participants');
            }

            if (!Schema::hasColumn('executive_documents', 'signatories')) {
                $table->jsonb('signatories')->nullable()->after('profile_data');
            }

            $table->index(['organization_id', 'work_type_id']);
            $table->index(['organization_id', 'journal_entry_id']);
            $table->index(['organization_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::table('executive_documents', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'work_type_id']);
            $table->dropIndex(['organization_id', 'journal_entry_id']);
            $table->dropIndex(['organization_id', 'document_type']);

            $table->dropConstrainedForeignId('work_type_id');
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropColumn([
                'document_date',
                'copies_count',
                'form_variant',
                'profile_data',
                'signatories',
            ]);
        });
    }
};
