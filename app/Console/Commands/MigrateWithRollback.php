<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

class MigrateWithRollback extends Command
{
    protected $signature = 'migrate:safe {--force : Force the operation to run in production}';
    
    protected $description = 'Run migrations with automatic rollback on failure (PostgreSQL transactions)';

    public function handle(): int
    {
        $this->info('🔄 Starting safe migration with automatic rollback...');
        
        $beforeMigrations = $this->getAppliedMigrations();
        $initialCount = count($beforeMigrations);
        
        $this->info("📊 Current migrations count: {$initialCount}");
        
        try {
            $exitCode = Artisan::call('migrate', [
                '--force' => $this->option('force')
            ]);
            
            if ($exitCode !== 0) {
                throw new \Exception('Migration command returned non-zero exit code');
            }
            
            $afterMigrations = $this->getAppliedMigrations();
            $newCount = count($afterMigrations) - $initialCount;
            
            if ($newCount > 0) {
                $this->info("✅ Successfully applied {$newCount} new migration(s)");
            } else {
                $this->info('✅ No new migrations to apply');
            }
            
            $this->newLine();
            $this->info('✅ All migrations completed successfully');
            
            return 0;
            
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('❌ Migration failed: ' . $e->getMessage());
            $this->newLine();
            
            $afterMigrations = $this->getAppliedMigrations();
            $newMigrations = count($afterMigrations) - $initialCount;
            
            if ($newMigrations > 0) {
                $this->warn("🔙 Attempting to rollback {$newMigrations} partially applied migration(s)...");
                
                try {
                    Artisan::call('migrate:rollback', [
                        '--step' => $newMigrations,
                        '--force' => true
                    ]);
                    
                    $finalMigrations = $this->getAppliedMigrations();
                    $finalCount = count($finalMigrations);
                    
                    if ($finalCount === $initialCount) {
                        $this->info('✅ Rollback completed successfully');
                        $this->info('✅ Database restored to original state');
                    } else {
                        $this->error('⚠️  Rollback may be incomplete');
                        $this->error("   Initial: {$initialCount}, Final: {$finalCount}");
                    }
                    
                } catch (Throwable $rollbackException) {
                    $this->error('❌ Rollback also failed: ' . $rollbackException->getMessage());
                    $this->error('⚠️  Database may be in inconsistent state!');
                    $this->error('   Please check migrations manually and restore from backup if needed');
                }
            } else {
                $this->warn('ℹ️  No migrations were applied, nothing to rollback');
            }
            
            $this->newLine();
            $this->error('❌ Migration process failed - deployment should be aborted');
            
            return 1;
        }
    }
    
    private function getAppliedMigrations(): array
    {
        try {
            return DB::table('migrations')
                ->orderBy('id')
                ->pluck('migration')
                ->toArray();
        } catch (Throwable $e) {
            $this->warn('Could not read migrations table: ' . $e->getMessage());
            return [];
        }
    }
}

