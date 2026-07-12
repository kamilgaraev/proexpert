<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\EstimateGenerationContractDatabaseProvisioner;

final class EstimateGenerationContractDatabaseProvisionerTest extends TestCase
{
    #[Test]
    public function guard_accepts_only_the_exact_disposable_postgres_endpoint(): void
    {
        $valid = [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 55432,
            'database' => 'most_ai_estimator_contract',
            'username' => 'most_contract_runner',
        ];
        putenv('ESTIMATE_GENERATION_CONTRACT_DB_ROLE=most_contract_runner');

        EstimateGenerationContractDatabaseProvisioner::assertSafe($valid, true);
        self::addToAssertionCount(1);

        foreach (['driver' => 'mysql', 'host' => '89.169.44.117', 'port' => 5432, 'database' => 'prohelper'] as $key => $value) {
            $invalid = $valid;
            $invalid[$key] = $value;
            try {
                EstimateGenerationContractDatabaseProvisioner::assertSafe($invalid, true);
                self::fail('Unsafe contract database configuration was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('estimate_generation_contract_database_unsafe', $exception->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);
        EstimateGenerationContractDatabaseProvisioner::assertSafe($valid, false);
    }

    #[Test]
    public function inventory_rejects_duplicates_missing_files_and_digest_tampering(): void
    {
        $root = sys_get_temp_dir().'/most-contract-inventory-'.bin2hex(random_bytes(5));
        mkdir($root);
        file_put_contents($root.'/a.php', 'a');
        file_put_contents($root.'/b.php', 'b');

        try {
            $entries = ['a.php', 'b.php'];
            $digest = EstimateGenerationContractDatabaseProvisioner::inventoryDigest($root, $entries);
            self::assertSame($entries, EstimateGenerationContractDatabaseProvisioner::validateInventory($root, $entries, $digest));

            foreach ([['a.php', 'a.php'], ['a.php', 'missing.php']] as $invalid) {
                try {
                    EstimateGenerationContractDatabaseProvisioner::validateInventory($root, $invalid, $digest);
                    self::fail('Invalid migration inventory was accepted.');
                } catch (InvalidArgumentException) {
                    self::addToAssertionCount(1);
                }
            }

            file_put_contents($root.'/b.php', 'tampered');
            $this->expectException(InvalidArgumentException::class);
            EstimateGenerationContractDatabaseProvisioner::validateInventory($root, $entries, $digest);
        } finally {
            @unlink($root.'/a.php');
            @unlink($root.'/b.php');
            @rmdir($root);
        }
    }

    #[Test]
    public function server_attestation_rejects_every_identity_and_privilege_mismatch(): void
    {
        $expected = ['database' => 'most_ai_estimator_contract', 'user' => 'most_contract_runner', 'marker_owner' => 'most_contract_guard', 'address' => '172.18.0.2', 'port' => 5432, 'marker' => (string) Str::uuid()];
        $facts = $expected + ['session_user' => 'most_contract_runner', 'marker_count' => 1,
            'marker_insert' => false, 'marker_update' => false, 'marker_delete' => false, 'superuser' => false,
            'createdb' => false, 'createrole' => false, 'replication' => false, 'bypassrls' => false];

        EstimateGenerationContractDatabaseProvisioner::validateAttestation($facts, $expected);
        self::addToAssertionCount(1);

        foreach ([
            'database' => 'prohelper', 'user' => 'postgres', 'session_user' => 'postgres', 'address' => '127.0.0.1',
            'port' => 55432, 'marker' => (string) Str::uuid(), 'marker_count' => 2,
            'marker_owner' => 'most_contract', 'marker_insert' => true, 'marker_update' => true, 'marker_delete' => true,
            'superuser' => true, 'createdb' => true, 'createrole' => true, 'replication' => true, 'bypassrls' => true,
        ] as $key => $invalidValue) {
            $invalid = $facts;
            $invalid[$key] = $invalidValue;
            try {
                EstimateGenerationContractDatabaseProvisioner::validateAttestation($invalid, $expected);
                self::fail('Invalid server attestation was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('estimate_generation_contract_server_attestation_failed', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function complete_inventory_registers_every_module_migration_once_and_in_order(): void
    {
        $root = dirname(__DIR__, 4);
        $registered = EstimateGenerationContractDatabaseProvisioner::completeInventory();
        $actual = glob($root.'/app/BusinessModules/Addons/EstimateGeneration/migrations/*.php');
        self::assertIsArray($actual);
        $actual = array_map(static fn (string $path): string => str_replace('\\', '/', substr($path, strlen($root) + 1)), $actual);
        sort($actual, SORT_STRING);
        $module = array_values(array_filter($registered, static fn (string $path): bool => str_starts_with($path, 'app/BusinessModules/Addons/EstimateGeneration/migrations/')));
        $sorted = $module;
        sort($sorted, SORT_STRING);

        self::assertSame($actual, $sorted);
        self::assertSame(count($registered), count(array_unique($registered, SORT_STRING)));
        self::assertSame([
            'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_001700_rebuild_estimate_generation_training_and_benchmarks.php',
            'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_001800_harden_estimate_generation_training_and_benchmarks.php',
            'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_001900_close_training_benchmark_edge_contracts.php',
            'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_002000_enforce_training_benchmark_storage_contracts.php',
            'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_002100_finalize_training_benchmark_architecture.php',
            'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_002200_close_training_benchmark_races.php',
        ], EstimateGenerationContractDatabaseProvisioner::subjectInventory('training', $root));
    }
}
