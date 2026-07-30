<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionSemanticDiff;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final class ReportDefinitionVersionPolicy
{
    private const VERSION_DIMENSIONS = [
        'formula' => 'formulaChanged',
        'source_schema' => 'sourceSchemaChanged',
        'contract' => 'contractChanged',
        'renderer' => 'rendererChanged',
    ];

    public function assertAllowed(
        array $current,
        array $candidate,
        ReportDefinitionConformanceEvidence $conformance,
    ): ReportDefinitionSemanticDiff {
        $currentVersions = $this->versions($current);
        $candidateVersions = $this->versions($candidate);
        if (! hash_equals($candidateVersions['formula'], $conformance->formula->formulaVersion)
            || ! hash_equals($candidateVersions['source_schema'], $conformance->sourceSchemaVersion)) {
            throw new InvalidArgumentException('report_definition_version_evidence_mismatch');
        }

        $diff = $this->diff($current, $candidate, $conformance);
        foreach (self::VERSION_DIMENSIONS as $version => $property) {
            $comparison = version_compare($candidateVersions[$version], $currentVersions[$version]);
            if ($diff->{$property} && $comparison <= 0) {
                throw new InvalidArgumentException('report_definition_version_change_required');
            }
            if (! $diff->{$property} && $comparison !== 0) {
                throw new InvalidArgumentException('report_definition_version_bump_without_change');
            }
        }

        return $diff;
    }

    public function diff(
        array $current,
        array $candidate,
        ReportDefinitionConformanceEvidence $conformance,
    ): ReportDefinitionSemanticDiff {
        $currentVersions = $this->versions($current);

        return new ReportDefinitionSemanticDiff(
            formulaChanged: $this->fingerprint([
                'formula_version' => $currentVersions['formula'],
            ]) !== $this->fingerprint([
                'formula_version' => $conformance->formula->formulaVersion,
            ]),
            sourceSchemaChanged: $this->fingerprint([
                'filters' => $current['filters'] ?? null,
                'grain' => $current['grain'] ?? null,
                'source_schema_version' => $currentVersions['source_schema'],
            ]) !== $this->fingerprint([
                'filters' => $candidate['filters'] ?? null,
                'grain' => $candidate['grain'] ?? null,
                'source_schema_version' => $conformance->sourceSchemaVersion,
            ]),
            contractChanged: $this->changed(
                $current,
                $candidate,
                ['filters', 'columns', 'sorts', 'formats', 'catalog_group'],
            ),
            rendererChanged: $this->changed(
                $current,
                $candidate,
                ['title_key', 'category', 'wave'],
            ),
            permissionsChanged: $this->changed($current, $candidate, ['permissions']),
            readinessChanged: $this->changed($current, $candidate, ['readiness', 'capabilities']),
        );
    }

    private function versions(array $definition): array
    {
        $versions = $definition['versions'] ?? null;
        if (! is_array($versions) || array_is_list($versions)) {
            throw new InvalidArgumentException('report_definition_versions_invalid');
        }

        $normalized = [];
        foreach (array_keys(self::VERSION_DIMENSIONS) as $name) {
            $value = $versions[$name] ?? null;
            if (! is_string($value)
                || preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
                throw new InvalidArgumentException('report_definition_versions_invalid');
            }
            $normalized[$name] = $value;
        }

        if (count($versions) !== count($normalized)) {
            throw new InvalidArgumentException('report_definition_versions_invalid');
        }

        return $normalized;
    }

    private function changed(array $current, array $candidate, array $keys): bool
    {
        $currentFingerprint = [];
        $candidateFingerprint = [];
        foreach ($keys as $key) {
            $currentFingerprint[$key] = $current[$key] ?? null;
            $candidateFingerprint[$key] = $candidate[$key] ?? null;
        }

        return $this->fingerprint($currentFingerprint)
            !== $this->fingerprint($candidateFingerprint);
    }

    private function fingerprint(array $value): string
    {
        return hash('sha256', CanonicalJson::encode($value));
    }
}
