<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Support;

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
        ];

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
}
