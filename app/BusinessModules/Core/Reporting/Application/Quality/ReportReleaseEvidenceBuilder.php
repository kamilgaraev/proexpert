<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Quality;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\LoadedReportManifest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogActivation;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQualityEvidenceLedger;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityEvidencePhase;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityEvidenceStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;

final class ReportReleaseEvidenceBuilder
{
    public function buildPlatform(LoadedReportManifest $managementManifest, LoadedReportManifest $officialManifest, array $gateEvidence, array $prerequisiteEvidence, string $releaseSha, DateTimeImmutable $generatedAt): ReportQualityEvidenceLedger
    {
        $this->assertReleaseSha($releaseSha);
        $this->assertGates($gateEvidence, ReportQualityEvidencePhase::PLATFORM, false, $releaseSha);
        $groups = [];
        foreach ($managementManifest->definitions as $definition) {
            $group = $definition['group'] ?? null;
            if (! is_string($group)) {
                throw new ReportQualityGateException(ReportQualityGateFailureCode::GROUP_COVERAGE_MISMATCH);
            }
            $groups[$group] = true;
        }
        if (count($groups) !== 7 || count($officialManifest->definitions) !== 1) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::GROUP_COVERAGE_MISMATCH);
        }
        return new ReportQualityEvidenceLedger('platform_passed', $releaseSha, $managementManifest->bytesHash, 28, 0, 0, array_keys($groups), $gateEvidence, $prerequisiteEvidence, $generatedAt);
    }

    public function buildRelease(ReportDefinitionRegistry $publishedRegistry, ReportDefinitionBindingMap $bindingMap, ReportCatalogActivation $activation, array $gateEvidence, array $prerequisiteEvidence, string $releaseSha, DateTimeImmutable $generatedAt): ReportQualityEvidenceLedger
    {
        $this->assertReleaseSha($releaseSha);
        $codes = $publishedRegistry->publishedCodes();
        if (count($codes) !== 28 || count($bindingMap->all()) !== 28 || ! $this->sameSet($codes, array_keys($bindingMap->all())) || $activation->releaseSha !== $releaseSha) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::BINDING_SET_MISMATCH);
        }
        $this->assertGates($gateEvidence, ReportQualityEvidencePhase::RELEASE, true, $releaseSha);
        return new ReportQualityEvidenceLedger('release_passed', $releaseSha, $publishedRegistry->manifestSha256(), 28, 28, 28, [], $gateEvidence, $prerequisiteEvidence, $generatedAt);
    }

    private function assertGates(array $gates, ReportQualityEvidencePhase $phase, bool $allPassed, string $releaseSha): void
    {
        if (! array_is_list($gates) || count($gates) !== 14) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::CATALOG_COUNT_MISMATCH);
        }
        $platformPassed = ['QG-01', 'QG-04', 'QG-05', 'QG-06', 'QG-09'];
        foreach ($gates as $index => $gate) {
            $expectedGate = sprintf('QG-%02d', $index + 1);
            $expectedStatus = $allPassed || in_array($expectedGate, $platformPassed, true)
                ? ReportQualityEvidenceStatus::PASSED
                : ReportQualityEvidenceStatus::PENDING;
            if (! $gate instanceof \App\BusinessModules\Core\Reporting\Domain\DTO\ReportQualityGateEvidence || $gate->gate !== $expectedGate || $gate->phase !== $phase || $gate->releaseSha !== $releaseSha || $gate->status !== $expectedStatus) {
                throw new ReportQualityGateException(ReportQualityGateFailureCode::PHASE_INCOMPLETE);
            }
        }
    }
    private function assertReleaseSha(string $value): void { if (preg_match('/^[a-f0-9]{40}$/', $value) !== 1) { throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID); } }
    private function sameSet(array $left, array $right): bool { sort($left, SORT_STRING); sort($right, SORT_STRING); return $left === $right; }
}
