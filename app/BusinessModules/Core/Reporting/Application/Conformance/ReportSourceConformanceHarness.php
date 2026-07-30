<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Conformance;

use App\BusinessModules\Core\Reporting\Application\Execution\CanonicalReportSourceHashBuilder;
use App\BusinessModules\Core\Reporting\Domain\DTO\CandidateReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportConformanceFixture;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownCell;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFormulaConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResourceLink;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use ReflectionClass;
use RuntimeException;
use Throwable;

final class ReportSourceConformanceHarness
{
    private const SOURCE_ASSERTIONS = [
        'availability',
        'binding_identity',
        'canonical_values',
        'page_cursor_semantics',
        'query_identity',
        'resource_links',
        'result_semantics',
        'row_count',
        'scope',
        'sensitive_redaction',
        'snapshot_identity',
        'source_hash',
        'unique_row_keys',
    ];

    public function verify(
        CandidateReportDefinition $candidate,
        ReportDefinitionBinding $binding,
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportConformanceFixture $fixture,
        string $commitSha,
        DateTimeImmutable $generatedAt,
    ): ReportDefinitionConformanceEvidence {
        $definition = $candidate->payload();
        $sourceChecks = array_fill_keys(self::SOURCE_ASSERTIONS, false);
        $formulaChecks = [
            'totals' => false,
            'version' => false,
        ];
        $zeroHash = new Sha256Hash(str_repeat('0', 64));
        $sourceHash = $zeroHash;
        $rowsHash = $zeroHash;
        $totalsHash = $zeroHash;
        $snapshotKind = 'unavailable';
        $snapshotId = 'unavailable';
        $rowCount = 0;

        $sourceChecks['binding_identity'] = $this->bindingIdentityMatches($definition, $binding);
        $sourceChecks['query_identity'] = $this->queryIdentityMatches($definition, $query);

        try {
            $progress = new ReportProgress(0);
            $snapshot = $binding->dataProvider->materialize($context, $query, $progress);
            $result = $binding->dataProvider->result($context, $snapshot);
            $page = $binding->rowQuery->page(
                $context,
                $snapshot,
                $fixture->sort,
                null,
                $fixture->pageLimit,
            );
            $rows = [];
            foreach ($binding->rowQuery->cursor(
                $context,
                $snapshot,
                $fixture->sort,
                $fixture->cursorChunkSize,
            ) as $row) {
                $rows[] = $row;
            }
            $drill = $binding->drillDownProvider->drillDown(
                $context,
                $snapshot,
                $this->drillDownInput($definition, $fixture),
            );

            $snapshotKind = $snapshot->kind;
            $snapshotId = $snapshot->id;
            $sourceHash = $snapshot->sourceHash;
            $rowCount = $result->metadata->rowCount;

            $sourceChecks['scope'] = $this->scopeMatches($context, $query, $snapshot);
            $sourceChecks['snapshot_identity'] = $this->snapshotIdentityMatches(
                $definition,
                $snapshot,
                $result,
            );
            $sourceChecks['availability'] = $result->freshness !== ReportFreshnessStatus::UNAVAILABLE
                && $page->freshness !== ReportFreshnessStatus::UNAVAILABLE;
            $sourceChecks['row_count'] = $rowCount === $fixture->expectedRowCount
                && count($rows) === $fixture->expectedRowCount;
            $sourceChecks['unique_row_keys'] = $this->hasUniqueRowKeys($rows);
            $sourceChecks['page_cursor_semantics'] = $this->pageMatchesCursor($page, $rows, $fixture);
            $sourceChecks['result_semantics'] = $this->resultSemanticsMatch(
                $definition,
                $snapshot,
                $result,
                $page,
            );
            $sourceChecks['resource_links'] = $this->hasSignedResourceLinks($drill);
            $sourceChecks['sensitive_redaction'] = $this->isRedacted(
                $definition,
                [$rows, $page->rows, $page->totals, $result->totals, $drill->rows],
            );

            try {
                $rowsHash = new Sha256Hash(hash('sha256', CanonicalJson::encode($rows)));
                $totalsHash = new Sha256Hash(hash('sha256', CanonicalJson::encode($result->totals)));
                CanonicalJson::encode($page->rows);
                CanonicalJson::encode($drill->rows);
                $sourceChecks['canonical_values'] = true;
            } catch (Throwable) {
                $sourceChecks['canonical_values'] = false;
            }

            try {
                $canonicalSourceHash = (new CanonicalReportSourceHashBuilder)->build(
                    $query,
                    $snapshot,
                    $result,
                );
                $sourceChecks['source_hash'] = hash_equals(
                    $snapshot->sourceHash->value,
                    $result->provenance->sourceHash->value,
                ) && hash_equals($snapshot->sourceHash->value, $canonicalSourceHash->value);
            } catch (Throwable) {
                $sourceChecks['source_hash'] = false;
            }

            $formulaChecks['version'] = hash_equals(
                $definition->formulaVersion,
                $snapshot->formulaVersion,
            );
            $formulaChecks['totals'] = hash_equals(
                $fixture->expectedTotalsHash->value,
                $totalsHash->value,
            );
        } catch (Throwable) {
            $sourceChecks['binding_identity'] = $this->bindingIdentityMatches($definition, $binding);
            $sourceChecks['query_identity'] = $this->queryIdentityMatches($definition, $query);
        }

        $sourceCodes = $this->assertionCodes('source', $sourceChecks);
        $formulaCodes = $this->assertionCodes('formula', $formulaChecks);
        $sourcePassed = $this->allPassed($sourceChecks);
        $formulaPassed = $this->allPassed($formulaChecks);
        $sourceEvidence = new ReportSourceConformanceEvidence(
            $sourceHash,
            $snapshotKind,
            $snapshotId,
            $rowCount,
            $rowsHash,
            $sourcePassed,
            $sourceCodes,
        );
        $formulaEvidence = new ReportFormulaConformanceEvidence(
            $definition->formulaVersion,
            $totalsHash,
            $formulaPassed,
            $formulaCodes,
        );

        return new ReportDefinitionConformanceEvidence(
            $definition->code,
            $definition->definitionHash,
            $definition->contractVersion,
            $definition->sourceSchemaVersion,
            $fixture->fixtureHash,
            $sourceEvidence,
            $formulaEvidence,
            $this->componentClassHashes($definition, $binding),
            count($sourceCodes) + count($formulaCodes),
            $sourcePassed && $formulaPassed ? 'passed' : 'failed',
            $commitSha,
            $generatedAt,
        );
    }

    private function bindingIdentityMatches(
        ReportDefinition $definition,
        ReportDefinitionBinding $binding,
    ): bool {
        return hash_equals($definition->code, $binding->code)
            && hash_equals($definition->definitionHash->value, $binding->definitionHash->value)
            && hash_equals($definition->contractVersion, $binding->contractVersion);
    }

    private function queryIdentityMatches(ReportDefinition $definition, ReportQuery $query): bool
    {
        return hash_equals($definition->code, $query->definition->code)
            && hash_equals($definition->definitionHash->value, $query->definition->definitionHash->value)
            && hash_equals($definition->contractVersion, $query->definition->contractVersion)
            && hash_equals($definition->formulaVersion, $query->definition->formulaVersion)
            && hash_equals($definition->sourceSchemaVersion, $query->definition->sourceSchemaVersion);
    }

    private function scopeMatches(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportSnapshotRef $snapshot,
    ): bool {
        return $context->scope->canonicalIdentity() === $query->scope->canonicalIdentity()
            && $query->scope->canonicalIdentity() === $snapshot->scope->canonicalIdentity();
    }

    private function snapshotIdentityMatches(
        ReportDefinition $definition,
        ReportSnapshotRef $snapshot,
        ReportResult $result,
    ): bool {
        return $result->metadata->snapshot === $snapshot
            && hash_equals($definition->definitionHash->value, $snapshot->definitionHash->value)
            && hash_equals($definition->formulaVersion, $snapshot->formulaVersion)
            && $result->metadata->generatedAt == $snapshot->generatedAt
            && $result->metadata->staleAt == $snapshot->staleAt;
    }

    private function pageMatchesCursor(
        ReportPage $page,
        array $rows,
        ReportConformanceFixture $fixture,
    ): bool {
        $expectedPageRows = array_slice($rows, 0, $fixture->pageLimit);

        return $this->canonicalEquals($page->rows, $expectedPageRows)
            && $page->limit === $fixture->pageLimit
            && $page->sort == $fixture->sort
            && $page->hasMore === (count($rows) > $fixture->pageLimit)
            && ($page->hasMore || $page->nextCursor === null);
    }

    private function resultSemanticsMatch(
        ReportDefinition $definition,
        ReportSnapshotRef $snapshot,
        ReportResult $result,
        ReportPage $page,
    ): bool {
        if (! $this->canonicalEquals($result->totals, $page->totals)
            || $this->qualityPayload($result->quality) !== $this->qualityPayload($page->quality)
            || ! hash_equals($snapshot->sourceHash->value, $result->provenance->sourceHash->value)) {
            return false;
        }

        foreach ($result->provenance->sourceRefs as $sourceRef) {
            if (! hash_equals($definition->sourceSchemaVersion, $sourceRef->schemaVersion)) {
                return false;
            }
        }

        return true;
    }

    private function canonicalEquals(array $left, array $right): bool
    {
        try {
            return hash_equals(CanonicalJson::encode($left), CanonicalJson::encode($right));
        } catch (Throwable) {
            return false;
        }
    }

    private function qualityPayload(ReportQuality $quality): array
    {
        return [
            'coverage' => $quality->coverage === null ? null : [
                'denominator' => $quality->coverage->denominator,
                'numerator' => $quality->coverage->numerator,
                'ratio' => $quality->coverage->ratio,
            ],
            'excluded_sources' => $quality->excludedSources,
            'reconciliation' => $quality->reconciliation->value,
            'status' => $quality->status->value,
            'unknown_metrics' => $quality->unknownMetrics,
            'unmatched_count' => $quality->unmatchedCount,
            'warnings' => array_map(
                static fn ($warning): array => [
                    'affected_row_count' => $warning->affectedRowCount,
                    'code' => $warning->code,
                    'metric' => $warning->metric,
                    'severity' => $warning->severity->value,
                ],
                $quality->warnings,
            ),
        ];
    }

    private function hasUniqueRowKeys(array $rows): bool
    {
        $keys = [];
        foreach ($rows as $row) {
            if (! is_array($row)
                || array_is_list($row)
                || ! isset($row['row_key'])
                || ! is_string($row['row_key'])
                || trim($row['row_key']) === ''
                || isset($keys[$row['row_key']])) {
                return false;
            }
            $keys[$row['row_key']] = true;
        }

        return true;
    }

    private function hasSignedResourceLinks(ReportDrillDownResult $drill): bool
    {
        foreach ($drill->resourceLinks as $link) {
            if (! $link instanceof ReportResourceLink
                || $link->availability !== 'available'
                || ! str_starts_with($link->routeName, 'admin.')
                || $link->params === []) {
                return false;
            }
        }

        return true;
    }

    private function isRedacted(ReportDefinition $definition, array $values): bool
    {
        $forbiddenKeys = array_fill_keys(
            array_merge(
                ['email', 'filters', 'password', 'phone', 'pii', 'query', 'secret', 'token', 'url'],
                $definition->outputClassification->sensitiveColumnIds,
            ),
            true,
        );

        foreach ($values as $value) {
            if ($this->containsForbiddenKey($value, $forbiddenKeys)) {
                return false;
            }
        }

        return true;
    }

    private function containsForbiddenKey(mixed $value, array $forbiddenKeys): bool
    {
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $key => $nested) {
            if (is_string($key) && isset($forbiddenKeys[strtolower($key)])) {
                return true;
            }
            if ($this->containsForbiddenKey($nested, $forbiddenKeys)) {
                return true;
            }
        }

        return false;
    }

    private function drillDownInput(
        ReportDefinition $definition,
        ReportConformanceFixture $fixture,
    ): ReportDrillDownInput {
        $columnId = $definition->columns[0]['id'];

        return new ReportDrillDownInput(
            new ReportDrillDownCell($fixture->drillDown->token, $columnId),
            $fixture->drillDown->cursor,
            $fixture->drillDown->limit,
        );
    }

    private function assertionCodes(string $group, array $checks): array
    {
        $codes = [];
        foreach ($checks as $code => $passed) {
            $codes[] = $group.'.'.$code.'.'.($passed ? 'passed' : 'failed');
        }
        sort($codes, SORT_STRING);

        return $codes;
    }

    private function allPassed(array $checks): bool
    {
        foreach ($checks as $passed) {
            if ($passed !== true) {
                return false;
            }
        }

        return true;
    }

    private function componentClassHashes(
        ReportDefinition $definition,
        ReportDefinitionBinding $binding,
    ): array {
        $components = [
            $definition::class,
            $binding->dataProvider::class,
            $binding->drillDownProvider::class,
            $binding->rowQuery::class,
        ];
        $hashes = [];
        foreach ($components as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            if (! is_string($file) || ! is_file($file)) {
                throw new RuntimeException('report_conformance_component_source_unavailable');
            }
            $hash = hash_file('sha256', $file);
            if (! is_string($hash)) {
                throw new RuntimeException('report_conformance_component_source_unavailable');
            }
            $hashes[$class] = new Sha256Hash($hash);
        }
        ksort($hashes, SORT_STRING);

        return $hashes;
    }
}
