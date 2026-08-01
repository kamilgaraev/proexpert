<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Application\Quality\ReportQualityGateException;
use App\BusinessModules\Core\Reporting\Domain\DTO\LoadedReportManifest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCandidateValidationResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogActivation;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;

final class ReportCatalogActivationService
{
    public function activate(LoadedReportManifest $current, LoadedReportManifest $candidate, ReportCandidateValidationResult $validation, iterable $candidateBindings, iterable $conformanceEvidence, array $planEvidenceDocuments, string $releaseSha, DateTimeImmutable $activatedAt): ReportCatalogActivation
    {
        $codes = array_map(static fn (array $row): string => (string) $row['code'], $candidate->definitions);
        $currentCodes = array_map(static fn (array $row): string => (string) $row['code'], $current->definitions);
        foreach ($candidate->definitions as $definition) {
            $readiness = $definition['readiness'] ?? null;
            if (! is_array($readiness)
                || ($readiness['source'] ?? null) !== 'ready'
                || ($readiness['formula'] ?? null) !== 'ready'
                || ($readiness['delivery'] ?? null) !== 'verified'
                || ($readiness['publication'] ?? null) !== 'candidate') {
                throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
            }
        }
        $bindingCodes = [];
        foreach ($candidateBindings as $binding) {
            if (! $binding instanceof ReportDefinitionBinding) {
                throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
            }
            $bindingCodes[] = $binding->code;
        }
        $conformance = [];
        foreach ($conformanceEvidence as $evidence) {
            if (! $evidence instanceof ReportDefinitionConformanceEvidence || ! $evidence->passed()) {
                throw new ReportQualityGateException(ReportQualityGateFailureCode::FAILED);
            }
            $conformance[$evidence->code] = $evidence;
        }
        if (count($codes) !== 28 || count(array_unique($codes)) !== 28 || count($bindingCodes) !== 28
            || count(array_unique($bindingCodes)) !== 28 || count($conformance) !== 28
            || ! $validation->passed() || count($validation->items) !== 28
            || ! $this->sameSet($codes, $currentCodes) || ! $this->sameSet($codes, $bindingCodes) || ! $this->sameSet($codes, array_keys($conformance))
            || ! $this->sameSet($codes, array_map(static fn ($item): string => $item->code, $validation->items))) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::BINDING_SET_MISMATCH);
        }
        if (count($planEvidenceDocuments) !== 2 || preg_match('/^[a-f0-9]{40}$/', $releaseSha) !== 1) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
        }
        $locks = [];
        $hashes = [];
        foreach ($codes as $code) {
            $hashes[] = $conformance[$code]->digest()->value;
            $locks[] = hash('sha256', $releaseSha.'|'.$code.'|'.$hashes[array_key_last($hashes)]);
        }
        return new ReportCatalogActivation('catalog_activated', $releaseSha, $current->bytesHash, $candidate->bytesHash, $codes, $bindingCodes, $locks, $hashes, $activatedAt);
    }

    private function sameSet(array $left, array $right): bool
    {
        sort($left, SORT_STRING);
        sort($right, SORT_STRING);
        return $left === $right;
    }
}
