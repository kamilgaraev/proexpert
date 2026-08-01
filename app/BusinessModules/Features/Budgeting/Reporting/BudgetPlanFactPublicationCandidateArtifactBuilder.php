<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFormulaConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationProof;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

final readonly class BudgetPlanFactPublicationCandidateArtifactBuilder
{
    public function __construct(
        private BudgetPlanFactReleaseCandidateResolver $resolver = new BudgetPlanFactReleaseCandidateResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $conformance
     * @param  array<string, mixed>  $proof
     */
    public function build(
        string $outputDirectory,
        string $commitSha,
        array $candidate,
        array $conformance,
        array $proof,
    ): void {
        if (preg_match('/^[a-f0-9]{40}$/D', $commitSha) !== 1
            || basename(str_replace('\\', '/', $outputDirectory)) !== 'v1'
            || is_link($outputDirectory)
            || file_exists($outputDirectory)
            || preg_match('#(?:^|[\\\\/])(?:\.|\.\.)(?:[\\\\/]|$)#D', str_replace('\\', '/', $outputDirectory)) === 1) {
            throw new InvalidArgumentException('budget_plan_fact_candidate_artifact_input_invalid');
        }

        try {
            $publicationProof = ReportPublicationProof::fromArray($proof);
            $conformanceEvidence = $this->conformanceEvidence($conformance);
            if (! isset($conformance['digest'])
                || ! is_string($conformance['digest'])
                || ! hash_equals($conformanceEvidence->digest()->value, $conformance['digest'])
                || ! hash_equals($conformanceEvidence->digest()->value, (string) ($proof['conformance_evidence_sha256'] ?? ''))) {
                throw new InvalidArgumentException('budget_plan_fact_candidate_artifact_invalid');
            }
            $request = BudgetPlanFactReleaseCandidateLayout::request($commitSha, $publicationProof->digest()->value);
            $documents = [
                BudgetPlanFactReleaseCandidateLayout::CANDIDATE_MANIFEST => $candidate,
                BudgetPlanFactReleaseCandidateLayout::CONFORMANCE_EVIDENCE => $conformance,
                BudgetPlanFactReleaseCandidateLayout::PROOF_TEMPLATE => $proof,
                BudgetPlanFactReleaseCandidateLayout::REQUEST_FILE => $request,
            ];
            $resolvedOutputDirectory = $this->resolvedOutputDirectory($outputDirectory);
            $this->write($resolvedOutputDirectory, $documents);
            $this->resolver->resolve($resolvedOutputDirectory, $commitSha);
        } catch (Throwable $exception) {
            $this->deleteDirectory($outputDirectory);
            throw $exception instanceof InvalidArgumentException
                ? $exception
                : new InvalidArgumentException('budget_plan_fact_candidate_artifact_invalid', 0, $exception);
        }
    }

    /** @param array<string, mixed> $document */
    private function conformanceEvidence(array $document): ReportDefinitionConformanceEvidence
    {
        $components = $document['component_class_hashes'] ?? null;
        $source = $document['source'] ?? null;
        $formula = $document['formula'] ?? null;
        if (! is_array($components) || ! array_is_list($components)
            || ! is_array($source) || array_is_list($source)
            || ! is_array($formula) || array_is_list($formula)) {
            throw new InvalidArgumentException('budget_plan_fact_candidate_artifact_invalid');
        }
        $hashes = [];
        foreach ($components as $component) {
            if (! is_array($component)
                || ! is_string($component['class'] ?? null)
                || ! is_string($component['sha256'] ?? null)) {
                throw new InvalidArgumentException('budget_plan_fact_candidate_artifact_invalid');
            }
            $hashes[$component['class']] = new Sha256Hash($component['sha256']);
        }
        try {
            $evidence = new ReportDefinitionConformanceEvidence(
                (string) ($document['code'] ?? ''),
                new Sha256Hash((string) ($document['definition_hash'] ?? '')),
                (string) ($document['contract_version'] ?? ''),
                (string) ($document['source_schema_version'] ?? ''),
                new Sha256Hash((string) ($document['fixture_hash'] ?? '')),
                new ReportSourceConformanceEvidence(
                    new Sha256Hash((string) ($source['source_hash'] ?? '')),
                    (string) ($source['snapshot_kind'] ?? ''),
                    (string) ($source['snapshot_id'] ?? ''),
                    $source['row_count'] ?? null,
                    new Sha256Hash((string) ($source['rows_hash'] ?? '')),
                    $source['passed'] ?? null,
                    $source['assertion_codes'] ?? null,
                ),
                new ReportFormulaConformanceEvidence(
                    (string) ($formula['formula_version'] ?? ''),
                    new Sha256Hash((string) ($formula['totals_hash'] ?? '')),
                    $formula['passed'] ?? null,
                    $formula['assertion_codes'] ?? null,
                ),
                $hashes,
                $document['assertion_count'] ?? null,
                (string) ($document['status'] ?? ''),
                (string) ($document['commit_sha'] ?? ''),
                new DateTimeImmutable((string) ($document['generated_at'] ?? '')),
            );
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('budget_plan_fact_candidate_artifact_invalid', 0, $exception);
        }
        $payload = $document;
        unset($payload['digest']);
        if ($payload !== $evidence->canonicalPayload()) {
            throw new InvalidArgumentException('budget_plan_fact_candidate_artifact_invalid');
        }

        return $evidence;
    }

    private function resolvedOutputDirectory(string $outputDirectory): string
    {
        $parent = dirname($outputDirectory);
        $missing = [];
        while (! file_exists($parent)) {
            if (is_link($parent)) {
                throw new InvalidArgumentException('budget_plan_fact_candidate_artifact_input_invalid');
            }
            $missing[] = basename($parent);
            $next = dirname($parent);
            if ($next === $parent) {
                throw new InvalidArgumentException('budget_plan_fact_candidate_artifact_input_invalid');
            }
            $parent = $next;
        }
        $this->assertNoSymlinkParents($parent);
        $resolvedParent = realpath($parent);
        if (! is_string($resolvedParent) || ! is_dir($resolvedParent)) {
            throw new InvalidArgumentException('budget_plan_fact_candidate_artifact_input_invalid');
        }
        foreach (array_reverse($missing) as $segment) {
            $resolvedParent .= DIRECTORY_SEPARATOR.$segment;
            if (! mkdir($resolvedParent, 0700) && ! is_dir($resolvedParent)) {
                throw new InvalidArgumentException('budget_plan_fact_candidate_artifact_output_invalid');
            }
            if (is_link($resolvedParent)) {
                throw new InvalidArgumentException('budget_plan_fact_candidate_artifact_input_invalid');
            }
        }
        $resolvedParent = realpath($resolvedParent);
        if (! is_string($resolvedParent) || ! is_dir($resolvedParent)) {
            throw new InvalidArgumentException('budget_plan_fact_candidate_artifact_output_invalid');
        }

        return $resolvedParent.DIRECTORY_SEPARATOR.'v1';
    }

    private function assertNoSymlinkParents(string $path): void
    {
        $current = $path;
        while (true) {
            if (is_link($current)) {
                throw new InvalidArgumentException('budget_plan_fact_candidate_artifact_input_invalid');
            }
            $parent = dirname($current);
            if ($parent === $current) {
                return;
            }
            $current = $parent;
        }
    }

    /** @param array<string, array<string, mixed>> $documents */
    private function write(string $outputDirectory, array $documents): void
    {
        if (! mkdir($outputDirectory, 0700, false) || is_link($outputDirectory)) {
            throw new InvalidArgumentException('budget_plan_fact_candidate_artifact_output_invalid');
        }

        foreach ($documents as $name => $document) {
            $path = $outputDirectory.DIRECTORY_SEPARATOR.$name;
            $handle = @fopen($path, 'x');
            if ($handle === false) {
                throw new InvalidArgumentException('budget_plan_fact_candidate_artifact_output_invalid');
            }
            try {
                if (fwrite($handle, CanonicalJson::encode($document)) === false) {
                    throw new InvalidArgumentException('budget_plan_fact_candidate_artifact_output_invalid');
                }
            } finally {
                fclose($handle);
            }
        }
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory) || is_link($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory.DIRECTORY_SEPARATOR.$entry;
            if (is_file($path) && ! is_link($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
