<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Catalog;

use App\BusinessModules\Core\Reporting\Domain\Contracts\CandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportConformanceEvidenceRepository;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportConformanceFixtureHashRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionCandidateValidator;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCandidateValidationResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use InvalidArgumentException;
use LogicException;

final class StrictReportDefinitionCandidateValidator implements ReportDefinitionCandidateValidator
{
    public function __construct(
        private ReportConformanceEvidenceRepository $evidence,
        private ReportConformanceFixtureHashRegistry $fixtures,
        private ReportBindingCompatibilityChecker $compatibility,
        private ReportCodeSetComparator $codes,
    ) {}

    public function validate(
        CandidateReportDefinitionRegistry $registry,
        iterable $bindings,
    ): ReportCandidateValidationResult {
        $byCode = [];
        foreach ($bindings as $binding) {
            if (! $binding instanceof ReportDefinitionBinding) {
                throw new InvalidArgumentException('candidate_binding_type_invalid');
            }
            if (array_key_exists($binding->code, $byCode)) {
                throw new LogicException('candidate_binding_duplicate');
            }
            $byCode[$binding->code] = $binding;
        }

        $candidateCodes = $this->codes->validate(
            $registry->candidateCodes(),
            'candidate_code',
        );
        $bindingCodes = $this->codes->validate(
            array_keys($byCode),
            'candidate_binding_code',
        );
        if (! $this->codes->equal($candidateCodes, $bindingCodes)) {
            throw new LogicException('candidate_binding_set_mismatch');
        }

        $items = [];
        foreach ($candidateCodes as $code) {
            $candidate = $registry->candidate($code);
            if (! hash_equals($code, $candidate->code)) {
                throw new LogicException('candidate_registry_identity_mismatch');
            }
            $fixtureHash = $this->fixtures->fixtureHash($code);
            $proof = $this->evidence->get(
                $code,
                $candidate->definitionHash,
                $fixtureHash,
            );
            $items[] = $this->compatibility->candidate(
                $candidate,
                $byCode[$code],
                $proof,
                $fixtureHash,
            );
        }

        return new ReportCandidateValidationResult($items);
    }
}
