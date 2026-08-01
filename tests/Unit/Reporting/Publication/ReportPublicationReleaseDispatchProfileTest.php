<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseDispatchProfile;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseRequest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReportPublicationReleaseDispatchProfileTest extends TestCase
{
    public function test_profile_rejects_a_request_for_another_report_before_any_feature_adapter_can_resolve_it(): void
    {
        $profile = new ReportPublicationReleaseDispatchProfile(
            'procurement_cycle',
            'r15_release_request',
            [
                'candidate_manifest' => 'r15-candidate-manifest.json',
                'conformance_evidence' => 'r15-conformance-evidence.json',
                'proof_template' => 'r15-proof-template.json',
            ],
        );
        $request = ReportPublicationReleaseRequest::fromArray([
            'request_id' => 'r15_release_request',
            'schema_version' => '1.0.0',
            'code' => 'other_cycle',
            'commit_sha' => str_repeat('a', 40),
            'proof_sha256' => str_repeat('b', 64),
            'artifact_paths' => [
                'candidate_manifest' => 'r15-candidate-manifest.json',
                'conformance_evidence' => 'r15-conformance-evidence.json',
                'proof_template' => 'r15-proof-template.json',
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_release_request_untrusted');

        $profile->assertRequest($request);
    }

    public function test_profile_derives_the_exact_artifact_filename_from_its_selected_code_and_proof(): void
    {
        $profile = new ReportPublicationReleaseDispatchProfile(
            'procurement_cycle',
            'r15_release_request',
            [
                'candidate_manifest' => 'r15-candidate-manifest.json',
                'conformance_evidence' => 'r15-conformance-evidence.json',
                'proof_template' => 'r15-proof-template.json',
            ],
        );

        self::assertSame(
            'report-publication-procurement_cycle-'.str_repeat('b', 64),
            $profile->artifactName(str_repeat('b', 64)),
        );
    }
}
