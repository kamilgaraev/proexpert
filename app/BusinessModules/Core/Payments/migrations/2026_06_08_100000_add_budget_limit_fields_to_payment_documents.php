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
            $table->foreignId('budget_article_id')
                ->nullable()
                ->after('estimate_id')
                ->constrained('budget_articles')
                ->nullOnDelete();
            $table->foreignId('responsibility_center_id')
                ->nullable()
                ->after('budget_article_id')
                ->constrained('responsibility_centers')
                ->nullOnDelete();
            $table->string('budget_limit_status', 32)->nullable()->after('workflow_stage');
            $table->string('budget_limit_decision', 48)->nullable()->after('budget_limit_status');
            $table->text('budget_limit_message')->nullable()->after('budget_limit_decision');
            $table->timestamp('budget_limit_checked_at')->nullable()->after('budget_limit_message');
            $table->text('budget_limit_override_reason')->nullable()->after('budget_limit_checked_at');
            $table->foreignId('budget_limit_overridden_by_user_id')
                ->nullable()
                ->after('budget_limit_override_reason')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['organization_id', 'budget_article_id'], 'payment_docs_budget_article_idx');
            $table->index(['organization_id', 'responsibility_center_id'], 'payment_docs_budget_center_idx');
            $table->index('budget_limit_status', 'payment_docs_budget_limit_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payment_documents', function (Blueprint $table): void {
            $table->dropIndex('payment_docs_budget_article_idx');
            $table->dropIndex('payment_docs_budget_center_idx');
            $table->dropIndex('payment_docs_budget_limit_status_idx');
            $table->dropForeign(['budget_article_id']);
            $table->dropForeign(['responsibility_center_id']);
            $table->dropForeign(['budget_limit_overridden_by_user_id']);
            $table->dropColumn([
                'budget_article_id',
                'responsibility_center_id',
                'budget_limit_status',
                'budget_limit_decision',
                'budget_limit_message',
                'budget_limit_checked_at',
                'budget_limit_override_reason',
                'budget_limit_overridden_by_user_id',
            ]);
        });
    }
};
