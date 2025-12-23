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
        Schema::table('payment_documents', function (Blueprint $table) {
            $table->foreignId('estimate_id')
                ->nullable()
                ->after('project_id')
                ->constrained('estimates')
                ->nullOnDelete()
                ->comment('Связанная смета');
            
            $table->index('estimate_id');
            $table->index(['estimate_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_documents', function (Blueprint $table) {
            $table->dropForeign(['estimate_id']);
            $table->dropIndex(['estimate_id']);
            $table->dropIndex(['estimate_id', 'status']);
            $table->dropColumn('estimate_id');
        });
    }
};

