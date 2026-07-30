<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ReportDefinitionConformanceEvidence
{
    public array $componentClassHashes;

    public function __construct(
        public string $code,
        public Sha256Hash $definitionHash,
        public string $contractVersion,
        public string $sourceSchemaVersion,
        public Sha256Hash $fixtureHash,
        public ReportSourceConformanceEvidence $source,
        public ReportFormulaConformanceEvidence $formula,
        array $componentClassHashes,
        public int $assertionCount,
        public string $status,
        public string $commitSha,
        public DateTimeImmutable $generatedAt,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $code) !== 1
            || trim($contractVersion) === ''
            || trim($sourceSchemaVersion) === ''
            || $assertionCount !== count($source->assertionCodes) + count($formula->assertionCodes)
            || ! in_array($status, ['passed', 'failed'], true)
            || preg_match('/^[a-f0-9]{40}$/D', $commitSha) !== 1) {
            throw new InvalidArgumentException('report_definition_conformance_evidence_invalid');
        }

        $this->componentClassHashes = self::normalizeComponentHashes($componentClassHashes);
        if (($status === 'passed') !== ($source->passed && $formula->passed)) {
            throw new InvalidArgumentException('report_definition_conformance_evidence_invalid');
        }
    }

    public function passed(): bool
    {
        if ($this->status !== 'passed' || ! $this->source->passed || ! $this->formula->passed) {
            return false;
        }

        foreach (array_merge($this->source->assertionCodes, $this->formula->assertionCodes) as $code) {
            if (str_ends_with($code, '.failed')) {
                return false;
            }
        }

        return true;
    }

    public function digest(): Sha256Hash
    {
        return new Sha256Hash(hash('sha256', CanonicalJson::encode($this->canonicalPayload())));
    }

    public function canonicalPayload(): array
    {
        $componentHashes = [];
        foreach ($this->componentClassHashes as $class => $hash) {
            $componentHashes[] = [
                'class' => $class,
                'sha256' => $hash->value,
            ];
        }

        return [
            'assertion_count' => $this->assertionCount,
            'code' => $this->code,
            'commit_sha' => $this->commitSha,
            'component_class_hashes' => $componentHashes,
            'contract_version' => $this->contractVersion,
            'definition_hash' => $this->definitionHash->value,
            'fixture_hash' => $this->fixtureHash->value,
            'formula' => [
                'assertion_codes' => $this->formula->assertionCodes,
                'formula_version' => $this->formula->formulaVersion,
                'passed' => $this->formula->passed,
                'totals_hash' => $this->formula->totalsHash->value,
            ],
            'generated_at' => $this->generatedAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.u\Z'),
            'source' => [
                'assertion_codes' => $this->source->assertionCodes,
                'passed' => $this->source->passed,
                'row_count' => $this->source->rowCount,
                'rows_hash' => $this->source->rowsHash->value,
                'snapshot_id' => $this->source->snapshotId,
                'snapshot_kind' => $this->source->snapshotKind,
                'source_hash' => $this->source->sourceHash->value,
            ],
            'source_schema_version' => $this->sourceSchemaVersion,
            'status' => $this->status,
        ];
    }

    private static function normalizeComponentHashes(array $hashes): array
    {
        if (array_is_list($hashes) || $hashes === []) {
            throw new InvalidArgumentException('report_definition_conformance_evidence_invalid');
        }

        $normalized = [];
        foreach ($hashes as $class => $hash) {
            if (! is_string($class)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]{0,255}$/D', $class) !== 1
                || ! $hash instanceof Sha256Hash
                || isset($normalized[$class])) {
                throw new InvalidArgumentException('report_definition_conformance_evidence_invalid');
            }
            $normalized[$class] = $hash;
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }
}
