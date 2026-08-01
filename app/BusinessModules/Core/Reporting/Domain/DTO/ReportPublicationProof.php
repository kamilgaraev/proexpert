<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ReportPublicationProof
{
    private const ROOT_KEYS = [
        'code',
        'candidate_manifest_sha256',
        'candidate_definition_sha256',
        'binding_sha256',
        'contract_version',
        'versions',
        'semantic_fingerprints',
        'fixture_sha256',
        'conformance_evidence_sha256',
        'source',
        'formula',
        'components',
        'permissions',
        'export_contracts',
        'drill_down_contract',
        'ci',
        'release',
    ];

    private function __construct(private array $data) {}

    public static function fromArray(array $payload): self
    {
        self::assertMap($payload, self::ROOT_KEYS);
        self::assertPattern($payload['code'], '/^[a-z][a-z0-9_]{2,63}$/D');
        foreach ([
            'candidate_manifest_sha256',
            'candidate_definition_sha256',
            'binding_sha256',
            'fixture_sha256',
            'conformance_evidence_sha256',
        ] as $field) {
            self::assertHash($payload[$field]);
        }
        self::assertVersion($payload['contract_version']);
        self::assertVersions($payload['versions']);
        self::assertFingerprints($payload['semantic_fingerprints']);
        self::assertSource($payload['source']);
        self::assertFormula($payload['formula']);
        self::assertComponents($payload['components']);
        self::assertPermissions($payload['permissions']);
        self::assertExports($payload['export_contracts']);
        self::assertDrillDown($payload['drill_down_contract']);
        self::assertCi($payload['ci']);
        self::assertRelease($payload['release']);

        return new self($payload);
    }

    public function payload(): array
    {
        return $this->data;
    }

    public function canonicalBytes(): string
    {
        return CanonicalJson::encode($this->data);
    }

    public function digest(): Sha256Hash
    {
        return new Sha256Hash(hash('sha256', $this->canonicalBytes()));
    }

    public function exportFormats(): array
    {
        return array_column($this->data['export_contracts'], 'format');
    }

    private static function assertVersions(mixed $versions): void
    {
        self::assertMap($versions, ['source_schema', 'formula', 'contract', 'renderer']);
        foreach ($versions as $version) {
            self::assertVersion($version);
        }
    }

    private static function assertFingerprints(mixed $fingerprints): void
    {
        self::assertMap($fingerprints, ['source', 'formula']);
        foreach ($fingerprints as $fingerprint) {
            self::assertHash($fingerprint);
        }
    }

    private static function assertSource(mixed $source): void
    {
        self::assertMap($source, [
            'snapshot_kind',
            'snapshot_id',
            'source_sha256',
            'rows_sha256',
            'row_count',
            'assertion_codes',
        ]);
        self::assertPattern($source['snapshot_kind'], '/^[a-z][a-z0-9_.:-]{0,63}$/D');
        self::assertPattern($source['snapshot_id'], '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D');
        self::assertHash($source['source_sha256']);
        self::assertHash($source['rows_sha256']);
        if (! is_int($source['row_count']) || $source['row_count'] < 0) {
            self::invalid();
        }
        self::assertCodes($source['assertion_codes'], '/^source\.[a-z][a-z0-9_]*\.passed$/D');
    }

    private static function assertFormula(mixed $formula): void
    {
        self::assertMap($formula, ['formula_version', 'totals_sha256', 'assertion_codes']);
        self::assertVersion($formula['formula_version']);
        self::assertHash($formula['totals_sha256']);
        self::assertCodes($formula['assertion_codes'], '/^formula\.[a-z][a-z0-9_]*\.passed$/D');
    }

    private static function assertComponents(mixed $components): void
    {
        if (! is_array($components) || ! array_is_list($components) || $components === []) {
            self::invalid();
        }

        $classes = [];
        foreach ($components as $component) {
            self::assertMap($component, ['class', 'sha256']);
            self::assertPattern($component['class'], '/^[A-Za-z_][A-Za-z0-9_\\\\]{0,255}$/D');
            self::assertHash($component['sha256']);
            if (isset($classes[$component['class']])) {
                self::invalid();
            }
            $classes[$component['class']] = true;
        }
        self::assertSorted(array_keys($classes));
    }

    private static function assertPermissions(mixed $permissions): void
    {
        self::assertMap($permissions, ['view', 'run', 'export', 'download', 'sensitive', 'audit']);
        foreach ($permissions as $group) {
            self::assertStringList($group, '/^[a-z0-9][a-z0-9._-]+$/D', allowEmpty: true);
            foreach ($group as $permission) {
                if ($permission === 'manage'
                    || str_ends_with($permission, '.manage')
                    || str_ends_with($permission, '_manage')
                    || str_ends_with($permission, '-manage')) {
                    self::invalid();
                }
            }
        }
        if ($permissions['view'] === []
            || $permissions['run'] === []
            || $permissions['export'] === []
            || $permissions['download'] === []) {
            self::invalid();
        }
    }

    private static function assertExports(mixed $contracts): void
    {
        if (! is_array($contracts) || ! array_is_list($contracts) || $contracts === []) {
            self::invalid();
        }
        $formats = [];
        foreach ($contracts as $contract) {
            self::assertMap($contract, [
                'format',
                'schema_sha256',
                'fixture_sha256',
                'renderer_class',
                'renderer_contract_sha256',
                'renderer_sha256',
                'assertion_codes',
            ]);
            if (! is_string($contract['format'])
                || ! in_array($contract['format'], ['csv', 'pdf', 'xlsx'], true)
                || isset($formats[$contract['format']])) {
                self::invalid();
            }
            $formats[$contract['format']] = true;
            self::assertHash($contract['schema_sha256']);
            self::assertHash($contract['fixture_sha256']);
            self::assertPattern($contract['renderer_class'], '/^[A-Za-z_][A-Za-z0-9_\\\\]{0,255}$/D');
            self::assertHash($contract['renderer_contract_sha256']);
            self::assertHash($contract['renderer_sha256']);
            self::assertCodes(
                $contract['assertion_codes'],
                '/^export\.'.preg_quote($contract['format'], '/').'\.[a-z][a-z0-9_]*\.passed$/D',
            );
        }
        self::assertSorted(array_keys($formats));
    }

    private static function assertDrillDown(mixed $contract): void
    {
        self::assertMap($contract, ['schema_sha256', 'assertion_codes']);
        self::assertHash($contract['schema_sha256']);
        self::assertCodes($contract['assertion_codes'], '/^drill_down\.[a-z][a-z0-9_]*\.passed$/D');
    }

    private static function assertCi(mixed $ci): void
    {
        self::assertMap($ci, ['run_id', 'commit_sha', 'suite_sha256', 'completed_at_utc', 'required_checks']);
        self::assertPattern($ci['run_id'], '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D');
        self::assertGitSha($ci['commit_sha']);
        self::assertHash($ci['suite_sha256']);
        self::assertTimestamp($ci['completed_at_utc']);
        self::assertStringList($ci['required_checks'], '/^[a-z][a-z0-9_]{2,63}$/D');
    }

    private static function assertRelease(mixed $release): void
    {
        self::assertMap($release, ['git_sha', 'created_at_utc', 'approver_identity']);
        self::assertGitSha($release['git_sha']);
        self::assertTimestamp($release['created_at_utc']);
        self::assertPattern($release['approver_identity'], '/^[A-Za-z0-9][A-Za-z0-9@._:-]{2,127}$/D');
    }

    private static function assertCodes(mixed $codes, string $pattern): void
    {
        self::assertStringList($codes, $pattern);
    }

    private static function assertStringList(mixed $values, string $pattern, bool $allowEmpty = false): void
    {
        if (! is_array($values) || ! array_is_list($values) || (! $allowEmpty && $values === [])) {
            self::invalid();
        }
        $seen = [];
        foreach ($values as $value) {
            self::assertPattern($value, $pattern);
            if (isset($seen[$value])) {
                self::invalid();
            }
            $seen[$value] = true;
        }
        self::assertSorted(array_keys($seen));
    }

    private static function assertMap(mixed $value, array $keys): void
    {
        if (! is_array($value) || array_is_list($value)) {
            self::invalid();
        }
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($actual !== $keys) {
            self::invalid();
        }
    }

    private static function assertHash(mixed $value): void
    {
        self::assertPattern($value, '/^[a-f0-9]{64}$/D');
    }

    private static function assertGitSha(mixed $value): void
    {
        self::assertPattern($value, '/^[a-f0-9]{40}$/D');
    }

    private static function assertVersion(mixed $value): void
    {
        self::assertPattern($value, '/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D');
    }

    private static function assertTimestamp(mixed $value): void
    {
        self::assertPattern(
            $value,
            '/^(?:19|20)[0-9]{2}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])T(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]\.[0-9]{6}Z$/D',
        );
        $timestamp = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.u\Z',
            $value,
            new DateTimeZone('UTC'),
        );
        $errors = DateTimeImmutable::getLastErrors();
        if (! $timestamp instanceof DateTimeImmutable
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $timestamp->format('Y-m-d\TH:i:s.u\Z') !== $value) {
            self::invalid();
        }
    }

    private static function assertPattern(mixed $value, string $pattern): void
    {
        if (! is_string($value) || preg_match($pattern, $value) !== 1) {
            self::invalid();
        }
    }

    private static function assertSorted(array $values): void
    {
        $sorted = $values;
        sort($sorted, SORT_STRING);
        if ($values !== $sorted) {
            self::invalid();
        }
    }

    private static function invalid(): never
    {
        throw new InvalidArgumentException('report_publication_proof_invalid');
    }
}
