<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('executive_document_remarks', function (Blueprint $table): void {
            $table->foreignId('answered_by')->nullable()->after('resolved_by')->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable()->after('resolved_at');
            $table->foreignId('reviewed_by')->nullable()->after('answered_by')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('answered_at');
            $table->text('review_comment')->nullable()->after('response');
            $table->index(['version_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('executive_document_remarks', function (Blueprint $table): void {
            $table->dropIndex(['version_id', 'status']);
            $table->dropForeign(['answered_by']);
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn(['answered_by', 'answered_at', 'reviewed_by', 'reviewed_at', 'review_comment']);
        });
    }
};
