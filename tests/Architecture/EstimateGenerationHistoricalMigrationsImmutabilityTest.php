<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationHistoricalMigrationsImmutabilityTest extends TestCase
{
    private const MIGRATION_HASHES = [
        '2026_07_12_001700_rebuild_estimate_generation_training_and_benchmarks.php' => 'fafa0274e725004c47da8cff4a18231c2405e7c3264049f10c7d76cbb0497e71',
        '2026_07_12_001800_harden_estimate_generation_training_and_benchmarks.php' => 'cfc5e0687185ba9f670cb4af310d950d72c1689d5b21da44ff5e37ddfd068e2c',
        '2026_07_12_001900_close_training_benchmark_edge_contracts.php' => 'a7881aa2d0d86a19ba49e61684cfd87ed39a203f354d8c619380458d7de83ee2',
        '2026_07_12_002000_enforce_training_benchmark_storage_contracts.php' => '4f0b10b7a3ce02c0f0d0eefdb34de7d2d960091c233ab86b8feeebf1c2264d1c',
        '2026_07_12_002100_finalize_training_benchmark_architecture.php' => 'ff871788ae29048d204887a3ea5ba4b4deb5f446f5633130871aecfc4a1c5637',
        '2026_07_12_002200_close_training_benchmark_races.php' => 'd6a6488bec216dffb77ef92a79cefedc714c793ff137743e0f13a730aae5f9aa',
        '2026_08_01_000150_add_project_model_projection_scope_indexes.php' => 'c082e63b6295a8737c6caadac2b78ab1baf99f2d621876e05f55cdd34c3349bf',
        '2026_08_01_000225_add_project_model_correction_scope_unique.php' => '1abc6ad91366ee052628c60e41995fe1e9fe1b680ac24cf638698a1fada08180',
        '2026_08_01_000250_bind_project_model_evidence_to_exact_candidate.php' => '5f3171a0fcb2b6a51a31f13b0c2bd283e763720ce1261f90054fb3ae411de944',
        '2026_08_01_000275_bind_project_model_evidence_to_canonical_locator.php' => '22fe34c2d85d6598ae2f50228e5eab4834d81e7dc7822e28b5156aa806c67e87',
        '2026_08_01_000300_create_estimate_generation_sheet_analysis_operations.php' => '9eb5cdf9e63299cc56befc60127f6caab1cf5a6374d2a032df65564e46c75a20',
    ];

    #[Test]
    public function applied_estimate_generation_migrations_remain_byte_for_byte_immutable(): void
    {
        $migrationDirectory = dirname(__DIR__, 2)
            .'/app/BusinessModules/Addons/EstimateGeneration/migrations';

        foreach (self::MIGRATION_HASHES as $filename => $expectedHash) {
            $path = $migrationDirectory.'/'.$filename;

            self::assertFileExists($path);
            self::assertSame($expectedHash, hash_file('sha256', $path), $filename);
        }
    }
}
