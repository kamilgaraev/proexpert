<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_change_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->enum('change_type', [
                'created',
                'updated',
                'deleted',
                'recalculated',
                'approved',
                'rejected',
                'item_added',
                'item_updated',
                'item_deleted',
                'section_added',
                'section_updated',
                'section_deleted',
                'other'
            ]);
            $table->string('entity_type', 100)->nullable();
            $table->bigInteger('entity_id')->nullable();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->text('comment')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('changed_at');
            $table->jsonb('metadata')->nullable();
            
            $table->index(['estimate_id', 'changed_at']);
            $table->index(['user_id', 'changed_at']);
            $table->index('change_type');
            $table->index(['entity_type', 'entity_id']);
        });

        DB::statement('CREATE INDEX estimate_change_log_old_values_gin_idx ON estimate_change_log USING GIN(old_values)');
        DB::statement('CREATE INDEX estimate_change_log_new_values_gin_idx ON estimate_change_log USING GIN(new_values)');

        DB::statement("
            CREATE TABLE estimate_change_log_partitioned (
                LIKE estimate_change_log INCLUDING ALL
            ) PARTITION BY RANGE (changed_at);
        ");

        $currentYear = date('Y');
        $currentMonth = date('m');
        
        for ($i = 0; $i < 12; $i++) {
            $month = str_pad($currentMonth, 2, '0', STR_PAD_LEFT);
            $nextMonth = str_pad(($currentMonth % 12) + 1, 2, '0', STR_PAD_LEFT);
            $nextYear = $currentMonth == 12 ? $currentYear + 1 : $currentYear;
            
            DB::statement("
                CREATE TABLE IF NOT EXISTS estimate_change_log_y{$currentYear}m{$month}
                PARTITION OF estimate_change_log_partitioned
                FOR VALUES FROM ('{$currentYear}-{$month}-01') TO ('{$nextYear}-{$nextMonth}-01');
            ");
            
            $currentMonth = ($currentMonth % 12) + 1;
            if ($currentMonth == 1) {
                $currentYear++;
            }
        }

        Schema::create('estimate_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained()->onDelete('cascade');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->enum('snapshot_type', ['manual', 'auto_approval', 'auto_periodic', 'before_major_change']);
            $table->string('label')->nullable();
            $table->text('description')->nullable();
            $table->jsonb('snapshot_data');
            $table->bigInteger('data_size')->default(0);
            $table->string('checksum', 64)->nullable();
            $table->timestamp('created_at');
            $table->jsonb('metadata')->nullable();
            
            $table->index(['estimate_id', 'created_at']);
            $table->index('snapshot_type');
        });

        DB::statement('CREATE INDEX estimate_snapshots_snapshot_data_gin_idx ON estimate_snapshots USING GIN(snapshot_data)');

        Schema::create('estimate_comparison_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id_1')->constrained('estimates')->onDelete('cascade');
            $table->foreignId('estimate_id_2')->constrained('estimates')->onDelete('cascade');
            $table->string('comparison_type', 50)->default('full');
            $table->jsonb('diff_data');
            $table->jsonb('summary')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('expires_at')->nullable();
            
            $table->index(['estimate_id_1', 'estimate_id_2']);
            $table->index('expires_at');
        });

        DB::statement('CREATE INDEX estimate_comparison_cache_diff_data_gin_idx ON estimate_comparison_cache USING GIN(diff_data)');

        DB::statement("
            CREATE OR REPLACE FUNCTION cleanup_expired_comparison_cache() RETURNS void AS $$
            BEGIN
                DELETE FROM estimate_comparison_cache WHERE expires_at < NOW();
            END
            $$ LANGUAGE plpgsql;
        ");
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS cleanup_expired_comparison_cache()');
        
        Schema::dropIfExists('estimate_comparison_cache');
        Schema::dropIfExists('estimate_snapshots');
        
        DB::statement('DROP TABLE IF EXISTS estimate_change_log_partitioned CASCADE');
        
        Schema::dropIfExists('estimate_change_log');
    }
};
