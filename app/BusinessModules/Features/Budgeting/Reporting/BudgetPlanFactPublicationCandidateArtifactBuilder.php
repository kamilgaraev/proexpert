<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationProof;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
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
            || file_exists($outputDirectory)) {
            throw new InvalidArgumentException('budget_plan_fact_candidate_artifact_input_invalid');
        }

        $parent = dirname($outputDirectory);
        if (! is_dir($parent) && ! mkdir($parent, 0700, true) && ! is_dir($parent)) {
            throw new InvalidArgumentException('budget_plan_fact_candidate_artifact_output_invalid');
        }
        $parentRealPath = realpath($parent);
        if (! is_string($parentRealPath) || is_link($parent) || ! is_dir($parentRealPath)) {
            throw new InvalidArgumentException('budget_plan_fact_candidate_artifact_input_invalid');
        }

        try {
            $publicationProof = ReportPublicationProof::fromArray($proof);
            $request = BudgetPlanFactReleaseCandidateLayout::request($commitSha, $publicationProof->digest()->value);
            $documents = [
                BudgetPlanFactReleaseCandidateLayout::CANDIDATE_MANIFEST => $candidate,
                BudgetPlanFactReleaseCandidateLayout::CONFORMANCE_EVIDENCE => $conformance,
                BudgetPlanFactReleaseCandidateLayout::PROOF_TEMPLATE => $proof,
                BudgetPlanFactReleaseCandidateLayout::REQUEST_FILE => $request,
            ];
            $this->write($outputDirectory, $documents);
            $this->resolver->resolve($outputDirectory, $commitSha);
        } catch (Throwable $exception) {
            $this->deleteDirectory($outputDirectory);
            throw $exception instanceof InvalidArgumentException
                ? $exception
                : new InvalidArgumentException('budget_plan_fact_candidate_artifact_invalid', 0, $exception);
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
