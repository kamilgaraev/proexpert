<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('estimates') || Schema::hasColumn('estimates', 'contract_id')) {
            return;
        }

        Schema::table('estimates', function (Blueprint $table) {
            $table->foreignId('contract_id')
                ->nullable()
                ->after('project_id')
                ->constrained('contracts')
                ->nullOnDelete()
                ->comment('Связанный договор');

            $table->index('contract_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('estimates') || !Schema::hasColumn('estimates', 'contract_id')) {
            return;
        }

        Schema::table('estimates', function (Blueprint $table) {
            $table->dropForeign(['contract_id']);
            $table->dropIndex(['contract_id']);
            $table->dropColumn('contract_id');
        });
    }
};
