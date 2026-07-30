<?php

declare(strict_types=1);

namespace Tests\Support\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Catalog\StrictReportDefinitionCandidateValidator;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportDefinitionCanonicalProjector;
use App\BusinessModules\Core\Reporting\Domain\Contracts\CandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\LoadedReportManifest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCandidateValidationItem;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;
use RuntimeException;

final readonly class ReportCandidateValidationFixtureBuilder
{
    public const CANDIDATE_PATH = 'tests/Fixtures/Reporting/Publication/candidate.valid.yaml';

    public function __construct(
        private StrictReportDefinitionCandidateValidator $validator,
        private ReportDefinitionFactory $definitions,
        private ReportDefinitionCanonicalProjector $projector,
    ) {}

    public function build(
        LoadedReportManifest $manifest,
        CandidateReportDefinitionRegistry $registry,
        array $bindings,
    ): ReportCandidateValidationFixture {
        if ($bindings === [] || array_is_list($bindings)) {
            throw new InvalidArgumentException('report_candidate_fixture_bindings_invalid');
        }
        foreach ($bindings as $code => $binding) {
            if (! is_string($code)
                || ! $binding instanceof ReportDefinitionBinding
                || ! hash_equals($code, $binding->code)) {
                throw new InvalidArgumentException('report_candidate_fixture_bindings_invalid');
            }
        }

        $expected = [];
        foreach ($manifest->definitions as $row) {
            $readiness = $row['readiness'] ?? null;
            if (is_array($readiness) && ($readiness['publication'] ?? null) === 'candidate') {
                $expected[] = $this->definitions->fromManifest($row);
            }
        }
        $registryCodes = $registry->candidateCodes();
        if (array_column($expected, 'code') !== $registryCodes) {
            throw new RuntimeException('report_candidate_fixture_registry_mismatch');
        }
        foreach ($expected as $ordinal => $definition) {
            $candidate = $registry->candidate($registryCodes[$ordinal]);
            if (! $this->projector->equals($definition, $candidate->payload())) {
                throw new RuntimeException('report_candidate_fixture_registry_mismatch');
            }
        }

        $result = $this->validator->validate($registry, $bindings);
        if (! $result->passed()) {
            throw new RuntimeException('report_candidate_fixture_validation_failed');
        }

        $codes = $registry->candidateCodes();
        if ($codes === [] || ! array_is_list($codes) || count($codes) !== count($result->items)) {
            throw new RuntimeException('report_candidate_fixture_validation_set_invalid');
        }

        $items = [];
        foreach ($codes as $ordinal => $code) {
            $item = $result->items[$ordinal] ?? null;
            $candidate = is_string($code) ? $registry->candidate($code) : null;
            if (! is_string($code)
                || ! $item instanceof ReportCandidateValidationItem
                || ! $item->passed
                || $item->failureCodes !== []
                || ! hash_equals($code, $item->code)
                || $candidate === null
                || ! hash_equals($candidate->definitionHash->value, $item->definitionHash->value)) {
                throw new RuntimeException('report_candidate_fixture_validation_set_invalid');
            }
            $items[] = [
                'code' => $item->code,
                'definition_hash' => $item->definitionHash->value,
                'failure_codes' => $item->failureCodes,
                'passed' => $item->passed,
            ];
        }

        $validationBytes = CanonicalJson::encode([
            'artifact_id' => 'report_candidate_validation',
            'candidate_manifest' => [
                'codes' => $codes,
                'path' => self::CANDIDATE_PATH,
                'sha256' => $manifest->bytesHash->value,
            ],
            'items' => $items,
            'schema_version' => '1.0.0',
            'status' => 'passed',
        ])."\n";

        return new ReportCandidateValidationFixture(
            $manifest->bytesHash->value."\n",
            $validationBytes,
            $result,
        );
    }
}

final readonly class ReportCandidateValidationFixture
{
    public function __construct(
        public string $checksumBytes,
        public string $validationBytes,
        public \App\BusinessModules\Core\Reporting\Domain\DTO\ReportCandidateValidationResult $validation,
    ) {}
}
