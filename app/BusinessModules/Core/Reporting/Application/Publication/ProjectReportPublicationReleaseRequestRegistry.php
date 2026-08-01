<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportConformanceEvidenceRepository;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\CandidateReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationProof;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final readonly class ProjectReportPublicationReleaseRequestRegistry implements ReportPublicationReleaseRequestResolver
{
    public function __construct(
        private string $trustedDirectory,
        private string $officialManifestBytes,
        private Sha256Hash $officialManifestHash,
        private ReportPublicationReleaseDispatchProfileCatalog $dispatches,
        private ReportDefinitionFactory $definitions,
        private ReportConformanceEvidenceRepository $evidence,
        private ReportPublicationReleaseEligibilityGate $gate,
        private ReportPublicationRegistry $publications,
    ) {
        $root = realpath($trustedDirectory);
        if (! is_string($root) || is_link($trustedDirectory) || ! is_dir($root)
            || $officialManifestBytes === ''
            || ! hash_equals($officialManifestHash->value, hash('sha256', $officialManifestBytes))) {
            throw new InvalidArgumentException('report_publication_release_composition_invalid');
        }
    }

    public function resolve(ReportPublicationReleaseRequest $request): ReportPublicationResolvedReleaseRequest
    {
        $dispatch = $this->dispatches->forCode($request->code);
        $dispatch->profile->assertRequest($request);
        $documents = $dispatch->candidateResolver->resolve($this->trustedDirectory, $request);
        $candidateManifest = $dispatch->profile->document($documents, 'candidate_manifest');
        $definitionDocument = $candidateManifest['candidate_definition'] ?? null;
        if (! is_array($definitionDocument)) {
            throw new InvalidArgumentException('report_publication_release_request_untrusted');
        }
        $definition = $this->definitions->fromManifest($definitionDocument);
        $candidate = new CandidateReportDefinition($definition);
        if (! hash_equals($dispatch->profile->code, $candidate->code)) {
            throw new InvalidArgumentException('report_publication_release_request_untrusted');
        }
        $previous = $this->publications->currentRecord($candidate->code);
        $binding = $dispatch->bindings->create($definition);
        $proof = ReportPublicationProof::fromArray($dispatch->profile->document($documents, 'proof_template'));
        $fixtureHash = new Sha256Hash($proof->payload()['fixture_sha256']);
        $evidence = $this->evidence->get($candidate->code, $candidate->definitionHash, $fixtureHash);
        $candidateManifestBytes = CanonicalJson::encode($candidateManifest);
        $conformanceDocument = $dispatch->profile->document($documents, 'conformance_evidence');
        if (($conformanceDocument['status'] ?? null) !== 'passed'
            || ($conformanceDocument['code'] ?? null) !== $candidate->code
            || ($conformanceDocument['commit_sha'] ?? null) !== $request->commitSha
            || ! hash_equals((string) ($conformanceDocument['definition_hash'] ?? ''), $candidate->definitionHash->value)
            || ! hash_equals((string) ($conformanceDocument['fixture_hash'] ?? ''), $evidence->fixtureHash->value)
            || ! hash_equals((string) ($conformanceDocument['digest'] ?? ''), $evidence->digest()->value)
            || ! hash_equals($proof->payload()['conformance_evidence_sha256'], $evidence->digest()->value)) {
            throw new InvalidArgumentException('report_publication_release_evidence_untrusted');
        }

        if (! hash_equals($request->proofSha256, $proof->digest()->value)
            || ! hash_equals($request->commitSha, $proof->payload()['release']['git_sha'])
            || ! hash_equals($request->commitSha, $evidence->commitSha)) {
            throw new InvalidArgumentException('report_publication_release_request_untrusted');
        }

        $admission = new ReportPublicationReleaseAdmission(
            $candidate,
            $definitionDocument,
            $binding,
            $evidence,
            $proof,
            $proof->payload()['ci']['required_checks'],
            $candidateManifestBytes,
            $this->officialManifestBytes,
            $previous,
        );
        $admission->assertProductionSafe();

        return new ReportPublicationResolvedReleaseRequest($admission, $this->gate);
    }
}
