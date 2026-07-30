<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Catalog;

use App\BusinessModules\Core\Reporting\Domain\DTO\CandidateReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCandidateValidationItem;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use LogicException;
use ReflectionClass;

final class ReportBindingCompatibilityChecker
{
    public function candidate(
        CandidateReportDefinition $candidate,
        ReportDefinitionBinding $binding,
        ReportDefinitionConformanceEvidence $evidence,
    ): ReportCandidateValidationItem {
        $definition = $candidate->payload();
        $failures = $this->identityFailures($definition, $binding);

        if (! hash_equals($definition->code, $evidence->code)) {
            $failures[] = 'EVIDENCE_CODE_MISMATCH';
        }
        if (! hash_equals($definition->definitionHash->value, $evidence->definitionHash->value)) {
            $failures[] = 'EVIDENCE_DEFINITION_HASH_MISMATCH';
        }
        if (! hash_equals($definition->contractVersion, $evidence->contractVersion)) {
            $failures[] = 'EVIDENCE_CONTRACT_VERSION_MISMATCH';
        }
        if (! hash_equals(
            hash('sha256', $definition->code),
            $evidence->fixtureHash->value,
        )) {
            $failures[] = 'EVIDENCE_FIXTURE_HASH_MISMATCH';
        }
        if (! $evidence->passed()) {
            $failures[] = 'EVIDENCE_NOT_PASSED';
        }

        foreach ($this->providerClasses($binding) as $class) {
            $expected = $this->classHash($class);
            $actual = $evidence->componentClassHashes[$class] ?? null;
            if (! $actual instanceof Sha256Hash
                || ! hash_equals($expected->value, $actual->value)) {
                $failures[] = 'EVIDENCE_PROVIDER_HASH_MISMATCH';
            }
        }

        $failures = array_values(array_unique($failures));

        return new ReportCandidateValidationItem(
            $definition->code,
            $definition->definitionHash,
            $failures === [],
            $failures,
        );
    }

    public function runtime(
        PublishedReportDefinition $published,
        ReportDefinitionBinding $binding,
    ): void {
        $definition = $published->payload();

        if ($this->identityFailures($definition, $binding) !== []) {
            throw new LogicException('published_binding_incompatible');
        }
        if ($binding->readinessProbe !== null
            && ! $binding->readinessProbe->supports($definition)) {
            throw new LogicException('published_binding_not_ready');
        }
    }

    private function identityFailures(
        ReportDefinition $definition,
        ReportDefinitionBinding $binding,
    ): array {
        $failures = [];
        if (! hash_equals($definition->code, $binding->code)) {
            $failures[] = 'BINDING_CODE_MISMATCH';
        }
        if (! hash_equals($definition->definitionHash->value, $binding->definitionHash->value)) {
            $failures[] = 'BINDING_DEFINITION_HASH_MISMATCH';
        }
        if (! hash_equals($definition->contractVersion, $binding->contractVersion)) {
            $failures[] = 'BINDING_CONTRACT_VERSION_MISMATCH';
        }

        return $failures;
    }

    private function providerClasses(ReportDefinitionBinding $binding): array
    {
        return [
            $binding->dataProvider::class,
            $binding->rowQuery::class,
            $binding->drillDownProvider::class,
        ];
    }

    private function classHash(string $class): Sha256Hash
    {
        $file = (new ReflectionClass($class))->getFileName();
        if (! is_string($file) || ! is_file($file)) {
            throw new LogicException('binding_provider_source_unavailable');
        }
        $hash = hash_file('sha256', $file);
        if (! is_string($hash)) {
            throw new LogicException('binding_provider_source_unavailable');
        }

        return new Sha256Hash($hash);
    }
}
