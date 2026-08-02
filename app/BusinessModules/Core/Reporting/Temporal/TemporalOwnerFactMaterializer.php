<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Temporal;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\ConnectionInterface;

final readonly class TemporalOwnerFactMaterializer
{
    public function __construct(
        private ConnectionInterface $connection,
        private TemporalOwnerFactResolver $resolver = new TemporalOwnerFactResolver,
    ) {}

    public function materializeExactState(
        ReportScope $scope,
        DateTimeImmutable $asOf,
        array $tables,
        string $unavailableCode,
    ): TemporalOwnerFactLease {
        $resourceProjectIds = array_map(
            static fn (object $resource): int => $resource->id,
            array_values(array_filter(
                $scope->resources,
                static fn (object $resource): bool => $resource->kind === 'project',
            )),
        );
        $projectIds = $scope->projectIds;
        if ($resourceProjectIds !== []) {
            $projectIds = $projectIds === []
                ? $resourceProjectIds
                : array_values(array_intersect($projectIds, $resourceProjectIds));
        }
        if ($scope->projectIds !== [] && $resourceProjectIds !== [] && $projectIds === []) {
            throw new DomainException($unavailableCode);
        }
        $schemaRecord = $this->connection->selectOne(
            "SELECT namespace.nspname AS schema_name
             FROM pg_class relation
             JOIN pg_namespace namespace ON namespace.oid = relation.relnamespace
             WHERE relation.relname = 'workforce_report_owner_facts'
               AND namespace.nspname NOT LIKE 'pg_temp_%'
             ORDER BY pg_table_is_visible(relation.oid) DESC, namespace.nspname
             LIMIT 1",
        );
        $schema = is_object($schemaRecord) ? (string) $schemaRecord->schema_name : '';
        if (preg_match('/^[a-z_][a-z0-9_]*$/D', $schema) !== 1) {
            throw new DomainException($unavailableCode);
        }
        $installed = [];
        foreach ($tables as $table) {
            if (! is_string($table) || preg_match('/^[a-z][a-z0-9_]*$/D', $table) !== 1) {
                throw new DomainException($unavailableCode);
            }
            $eligibility = $this->connection->table('workforce_report_owner_fact_eligibility')
                ->where('organization_id', $scope->organizationId)
                ->where('source_table', $table)
                ->first();
            if ($eligibility === null
                || new DateTimeImmutable((string) $eligibility->eligible_from) > $asOf) {
                throw new DomainException($unavailableCode);
            }
            $facts = $this->connection->table('workforce_report_owner_facts')
                ->where('organization_id', $scope->organizationId)
                ->where('source_table', $table)
                ->orderBy('source_id')
                ->orderBy('recorded_at')
                ->orderBy('sequence')
                ->get();
            $temporalPayloads = $this->resolver->payloadsAt($facts, $asOf, $projectIds);
            try {
                $this->connection->statement(
                    "CREATE TEMP TABLE pg_temp.\"{$table}\"
                     (LIKE \"{$schema}\".\"{$table}\"
                         INCLUDING DEFAULTS
                         INCLUDING GENERATED
                         INCLUDING IDENTITY
                         INCLUDING CONSTRAINTS)
                     ON COMMIT PRESERVE ROWS",
                );
                $installed[] = $table;
                if ($temporalPayloads !== []) {
                    $this->connection->statement(
                        "INSERT INTO pg_temp.\"{$table}\"
                         SELECT (jsonb_populate_record(
                             NULL::pg_temp.\"{$table}\",
                             owner_payload.value
                         )).*
                         FROM jsonb_array_elements(?::jsonb) AS owner_payload(value)",
                        [CanonicalJson::encode(array_values($temporalPayloads))],
                    );
                }
            } catch (\Throwable) {
                foreach (array_reverse($installed) as $installedTable) {
                    $this->connection->statement(
                        "DROP TABLE IF EXISTS pg_temp.\"{$installedTable}\"",
                    );
                }
                throw new DomainException($unavailableCode);
            }
        }

        return new TemporalOwnerFactLease($this->connection, $installed);
    }
}
