<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Execution;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class CanonicalReportSourceHashBuilder
{
    private const SOURCE_REF_SORT_FIELDS = [
        'source',
        'snapshot_kind',
        'snapshot_id',
        'schema_version',
        'watermark',
        'row_count',
        'hash',
    ];

    public function build(ReportQuery $query, ReportSnapshotRef $snapshot, ReportResult $result): Sha256Hash
    {
        if (! hash_equals($snapshot->sourceHash->value, $result->provenance->sourceHash->value)
            || ! hash_equals($snapshot->sourceHash->value, $result->metadata->snapshot->sourceHash->value)) {
            throw new InvalidArgumentException('report_source_hash_identity_mismatch');
        }

        return $snapshot->sourceHash;
    }

    public function snapshotIdentity(
        ReportQuery $query,
        ReportSnapshotRef $snapshot,
        ReportResult $result,
    ): Sha256Hash {
        $sourceRefs = [];
        $identities = [];
        foreach ($result->provenance->sourceRefs as $ref) {
            $projected = [
                'source' => $ref->source,
                'snapshot_kind' => $ref->snapshotKind,
                'snapshot_id' => $ref->snapshotId,
                'schema_version' => $ref->schemaVersion,
                'watermark' => $ref->watermark,
                'row_count' => $ref->rowCount,
                'hash' => $ref->hash->value,
            ];
            $identity = CanonicalJson::encode(array_intersect_key($projected, array_flip([
                'source',
                'snapshot_kind',
                'snapshot_id',
                'schema_version',
                'watermark',
            ])));
            if (isset($identities[$identity])) {
                throw new InvalidArgumentException('report_source_hash_invalid');
            }
            $identities[$identity] = true;
            $sourceRefs[] = $projected;
        }

        usort($sourceRefs, static function (array $left, array $right): int {
            foreach (self::SOURCE_REF_SORT_FIELDS as $field) {
                $comparison = $left[$field] <=> $right[$field];
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return 0;
        });

        $projection = [
            'query' => [
                'definition_hash' => $query->definition->definitionHash->value,
                'query_hash' => $query->queryHash->value,
                'contract_version' => $query->definition->contractVersion,
                'formula_version' => $query->definition->formulaVersion,
                'source_schema_version' => $query->definition->sourceSchemaVersion,
                'renderer_version' => $query->definition->rendererVersion,
                'canonical_query_json' => json_decode($query->canonicalJson, true, 512, JSON_THROW_ON_ERROR),
            ],
            'snapshot' => [
                'kind' => $snapshot->kind,
                'id' => $snapshot->id,
                'scope' => $snapshot->scope->canonicalIdentity(),
                'definition_hash' => $snapshot->definitionHash->value,
                'formula_version' => $snapshot->formulaVersion,
                'generated_at' => $this->utc($snapshot->generatedAt),
                'stale_at' => $snapshot->staleAt === null ? null : $this->utc($snapshot->staleAt),
                'watermarks' => $snapshot->watermarks,
            ],
            'result' => [
                'row_count' => $result->metadata->rowCount,
                'provenance' => [
                    'source_of_truth' => $result->provenance->sourceOfTruth,
                    'external_confirmation_role' => $result->provenance->externalConfirmationRole,
                    'source_refs' => $sourceRefs,
                ],
            ],
        ];

        $this->assertCanonicalNumbers($projection);

        return new Sha256Hash(hash('sha256', CanonicalJson::encode($projection)));
    }

    private function assertCanonicalNumbers(mixed $value): void
    {
        if (is_float($value)) {
            throw new InvalidArgumentException('report_source_hash_invalid');
        }
        if (is_string($value)
            && preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/D', $value) === 1
            && (preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]*[1-9])?$/D', $value) !== 1 || $value === '-0')) {
            throw new InvalidArgumentException('report_source_hash_invalid');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertCanonicalNumbers($item);
            }
        }
    }

    private function utc(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
