<?php

namespace App\Console\Commands;

use App\Contracts\Database\ForwardOnlyMigration;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
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
                '--force' => $this->option('force'),
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
            $this->error('❌ Migration failed: '.$e->getMessage());
            $this->newLine();

            $afterMigrations = $this->getAppliedMigrations();
            $newlyApplied = array_values(array_diff($afterMigrations, $beforeMigrations));
            $newMigrations = count($newlyApplied);

            if ($newMigrations > 0) {
                $plan = MigrationRollbackPlan::forApplied(
                    $newlyApplied,
                    fn (string $migration): bool => $this->isForwardOnly($migration),
                );
                if ($plan->requiresFixForward) {
                    $this->error('Forward-only migrations were applied. Schema and migration records are preserved; fix-forward and retry are required.');
                }
                if ($plan->rollbackSteps === 0) {
                    $this->warn('No reversible migration tail can be rolled back safely.');
                } else {
                    $this->warn("🔙 Attempting to rollback {$plan->rollbackSteps} reversible tail migration(s)...");

                    try {
                        Artisan::call('migrate:rollback', [
                            '--step' => $plan->rollbackSteps,
                            '--force' => true,
                        ]);

                        $finalMigrations = $this->getAppliedMigrations();
                        $finalCount = count($finalMigrations);

                        $expectedCount = $initialCount + count($plan->preservedMigrations);
                        if ($finalCount === $expectedCount) {
                            $this->info('✅ Rollback completed successfully');
                            if (! $plan->requiresFixForward) {
                                $this->info('✅ Database restored to original state');
                            }
                        } else {
                            $this->error('⚠️  Rollback may be incomplete');
                            $this->error("   Expected: {$expectedCount}, Final: {$finalCount}");
                        }

                    } catch (Throwable $rollbackException) {
                        $this->error('❌ Rollback also failed: '.$rollbackException->getMessage());
                        $this->error('⚠️  Database may be in inconsistent state!');
                        $this->error('   Please check migrations manually and restore from backup if needed');
                    }
                }
            } else {
                $this->warn('ℹ️  No migrations were applied, nothing to rollback');
            }

            $this->newLine();
            $this->error('❌ Migration process failed - deployment should be aborted');

            return 1;
        }
    }

    private function isForwardOnly(string $migrationName): bool
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');
        $files = $migrator->getMigrationFiles(array_values(array_unique([
            database_path('migrations'),
            ...$migrator->paths(),
        ])));
        $path = $files[$migrationName] ?? null;
        if (! is_string($path)) {
            return false;
        }
        $resolve = \Closure::bind(
            fn (string $migrationPath): object => $this->resolvePath($migrationPath),
            $migrator,
            $migrator,
        );

        return $resolve($path) instanceof ForwardOnlyMigration;
    }

    private function getAppliedMigrations(): array
    {
        try {
            return DB::table('migrations')
                ->orderBy('id')
                ->pluck('migration')
                ->toArray();
        } catch (Throwable $e) {
            $this->warn('Could not read migrations table: '.$e->getMessage());

            return [];
        }
    }
}
