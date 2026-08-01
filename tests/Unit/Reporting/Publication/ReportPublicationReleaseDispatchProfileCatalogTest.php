<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseBindingFactory;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseCandidateResolver;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseDispatch;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseDispatchProfile;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseDispatchProfileCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReportPublicationReleaseDispatchProfileCatalogTest extends TestCase
{
    public function test_selects_only_the_profile_encoded_in_the_release_artifact_name(): void
    {
        $procurement = $this->dispatch('procurement_cycle');
        $other = $this->dispatch('other_cycle');
        $catalog = new ReportPublicationReleaseDispatchProfileCatalog([$procurement, $other]);

        self::assertSame($procurement, $catalog->forArtifactName($procurement->profile->artifactName(str_repeat('a', 64))));
        self::assertSame($other, $catalog->forArtifactName($other->profile->artifactName(str_repeat('b', 64))));
    }

    public function test_rejects_an_artifact_name_without_an_exact_profile_match(): void
    {
        $catalog = new ReportPublicationReleaseDispatchProfileCatalog([$this->dispatch('procurement_cycle')]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_release_input_invalid');

        $catalog->forArtifactName('report-publication-other_cycle-'.str_repeat('a', 64));
    }

    private function dispatch(string $code): ReportPublicationReleaseDispatch
    {
        return new ReportPublicationReleaseDispatch(
            new ReportPublicationReleaseDispatchProfile(
                $code,
                $code.'_release_request',
                [
                    'candidate_manifest' => 'r15-candidate-manifest.json',
                    'conformance_evidence' => 'r15-conformance-evidence.json',
                    'proof_template' => 'r15-proof-template.json',
                ],
            ),
            new class implements ReportPublicationReleaseCandidateResolver
            {
                public function resolve(string $trustedDirectory, \App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseRequest $request): array
                {
                    return [];
                }
            },
            new class implements ReportPublicationReleaseBindingFactory
            {
                public function create(\App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition $definition): \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding
                {
                    throw new \LogicException('not_called');
                }
            },
        );
    }
}
