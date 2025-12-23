<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            if (!Schema::hasColumn('estimates', 'contract_id')) {
                $table->foreignId('contract_id')
                    ->nullable()
                    ->after('project_id')
                    ->constrained('contracts')
                    ->nullOnDelete()
                    ->comment('Связанный договор');
                
                $table->index('contract_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            if (Schema::hasColumn('estimates', 'contract_id')) {
                $table->dropForeign(['contract_id']);
                $table->dropIndex(['contract_id']);
                $table->dropColumn('contract_id');
            }
        });
    }
};

