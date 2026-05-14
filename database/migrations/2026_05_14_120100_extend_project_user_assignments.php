<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_user')) {
            return;
        }

        Schema::table('project_user', function (Blueprint $table): void {
            if (! Schema::hasColumn('project_user', 'assigned_by_user_id')) {
                $table
                    ->foreignId('assigned_by_user_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('project_user', 'is_active')) {
                $table
                    ->boolean('is_active')
                    ->default(true)
                    ->after('role');
            }

            if (! Schema::hasColumn('project_user', 'assigned_at')) {
                $table
                    ->timestamp('assigned_at')
                    ->nullable()
                    ->after('is_active');
            }
        });

        Schema::table('project_user', function (Blueprint $table): void {
            $table->index(['user_id', 'is_active'], 'project_user_user_active_index');
            $table->index(['project_id', 'is_active'], 'project_user_project_active_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('project_user')) {
            return;
        }

        Schema::table('project_user', function (Blueprint $table): void {
            try {
                $table->dropIndex('project_user_user_active_index');
            } catch (\Throwable) {
            }

            try {
                $table->dropIndex('project_user_project_active_index');
            } catch (\Throwable) {
            }
        });

        Schema::table('project_user', function (Blueprint $table): void {
            if (Schema::hasColumn('project_user', 'assigned_by_user_id')) {
                try {
                    $table->dropForeign(['assigned_by_user_id']);
                } catch (\Throwable) {
                }

                $table->dropColumn('assigned_by_user_id');
            }

            if (Schema::hasColumn('project_user', 'assigned_at')) {
                $table->dropColumn('assigned_at');
            }

            if (Schema::hasColumn('project_user', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }
};
