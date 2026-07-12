<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use Illuminate\Database\ConnectionInterface;

final class PackageInputVersionBackfill
{
    public const SQL = <<<'SQL'
        UPDATE estimate_generation_packages
        SET input_version = metadata->>'input_version'
        WHERE input_version IS NULL
          AND metadata->>'generated_from' = 'estimate_generation_v2'
          AND metadata->>'input_version' ~ '^sha256:[a-f0-9]{64}$'
        SQL;

    public function run(ConnectionInterface $connection): int
    {
        return $connection->affectingStatement(self::SQL);
    }
}
