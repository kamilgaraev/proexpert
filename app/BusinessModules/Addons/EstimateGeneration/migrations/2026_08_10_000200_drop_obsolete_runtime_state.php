<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('estimate_generation_training_datasets')) {
            DB::table('estimate_generation_training_datasets')
                ->where('status', 'processing')
                ->update([
                    'status' => 'draft',
                    'processing_token' => null,
                    'processing_lease_expires_at' => null,
                ]);

            DB::statement('DROP TRIGGER IF EXISTS eg_training_lease_write_fence ON estimate_generation_training_datasets');
            DB::statement('DROP FUNCTION IF EXISTS eg_training_lease_write_fence()');
            DB::statement('DROP INDEX IF EXISTS eg_training_processing_lease_idx');
            DB::statement('ALTER TABLE estimate_generation_training_datasets DROP CONSTRAINT IF EXISTS eg_training_processing_lease_chk');
            DB::statement('ALTER TABLE estimate_generation_training_datasets DROP CONSTRAINT IF EXISTS eg_training_processing_token_chk');
            DB::statement('ALTER TABLE estimate_generation_training_datasets DROP CONSTRAINT IF EXISTS eg_training_dataset_status_chk');
            DB::statement('ALTER TABLE estimate_generation_training_datasets DROP COLUMN IF EXISTS processing_token');
            DB::statement('ALTER TABLE estimate_generation_training_datasets DROP COLUMN IF EXISTS processing_lease_expires_at');
            DB::statement('ALTER TABLE estimate_generation_training_datasets DROP COLUMN IF EXISTS processing_attempt');
            DB::statement("ALTER TABLE estimate_generation_training_datasets ADD CONSTRAINT eg_training_dataset_status_chk CHECK (status IN ('draft','review_required','approved','rejected','archived'))");
        }

        Schema::dropIfExists('estimate_generation_finalization_deliveries');
        DB::statement('DROP FUNCTION IF EXISTS eg_finalization_delivery_immutable_guard()');
        Schema::dropIfExists('estimate_generation_finalization_outbox');
    }

    public function down(): void
    {
        throw new \RuntimeException('Obsolete estimate generation runtime cleanup is irreversible.');
    }
};
