<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseBundleWriter;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseBundleFileLoader;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationReleaseBundle;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Reporting\Publication\ReportPublicationFixtureFactory;
use Tests\Support\Reporting\Publication\ReportPublicationReleaseArtifactTestFactory;

final class ReportPublicationReleaseBundleWriterTest extends TestCase
{
    public function test_removes_a_serialized_bundle_when_reverification_fails(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-release-output-'.bin2hex(random_bytes(8));
        $fixture = ReportPublicationFixtureFactory::eligible();
        $bundle = new ReportPublicationReleaseBundle(
            $fixture['eligible']->proof,
            '{"tampered":true}',
            'report-publication-'.$fixture['eligible']->candidate->code.'-'.$fixture['eligible']->proof->digest()->value,
        );

        try {
            $this->expectException(\InvalidArgumentException::class);

            (new ReportPublicationReleaseBundleWriter(
                new ReportPublicationReleaseBundleFileLoader,
                ReportPublicationReleaseArtifactTestFactory::verifier(),
            ))->write($bundle, $directory);
        } finally {
            self::assertFileDoesNotExist($directory.DIRECTORY_SEPARATOR.$bundle->artifactName.'.proof.json');
            self::assertFileDoesNotExist($directory.DIRECTORY_SEPARATOR.$bundle->artifactName.'.json');
            @rmdir($directory);
        }
    }

    public function test_reopens_and_verifies_the_serialized_bundle_before_returning(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-release-output-'.bin2hex(random_bytes(8));
        $fixture = ReportPublicationFixtureFactory::eligible();
        $bundle = new ReportPublicationReleaseBundle(
            $fixture['eligible']->proof,
            $fixture['eligible']->releaseArtifactBytes,
            'report-publication-'.$fixture['eligible']->candidate->code.'-'.$fixture['eligible']->proof->digest()->value,
        );

        (new ReportPublicationReleaseBundleWriter(
            new ReportPublicationReleaseBundleFileLoader,
            ReportPublicationReleaseArtifactTestFactory::verifier(),
        ))->write($bundle, $directory);

        try {
            self::assertSame($bundle->proof->canonicalBytes(), file_get_contents($directory.DIRECTORY_SEPARATOR.$bundle->artifactName.'.proof.json'));
            self::assertSame($bundle->artifactBytes, file_get_contents($directory.DIRECTORY_SEPARATOR.$bundle->artifactName.'.json'));
        } finally {
            @unlink($directory.DIRECTORY_SEPARATOR.$bundle->artifactName.'.json');
            @unlink($directory.DIRECTORY_SEPARATOR.$bundle->artifactName.'.proof.json');
            @rmdir($directory);
        }
    }

    public function test_existing_artifact_name_is_never_overwritten(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-release-output-'.bin2hex(random_bytes(8));
        $fixture = ReportPublicationFixtureFactory::eligible();
        $proof = $fixture['eligible']->proof;
        $artifactName = 'report-publication-'.$fixture['eligible']->candidate->code.'-'.$proof->digest()->value;
        $bundle = new ReportPublicationReleaseBundle($proof, $fixture['eligible']->releaseArtifactBytes, $artifactName);
        $writer = new ReportPublicationReleaseBundleWriter(
            new ReportPublicationReleaseBundleFileLoader,
            ReportPublicationReleaseArtifactTestFactory::verifier(),
        );
        $writer->write($bundle, $directory);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('report_publication_release_output_exists');

            $writer->write(new ReportPublicationReleaseBundle($proof, 'replacement', $artifactName), $directory);
        } finally {
            self::assertSame($fixture['eligible']->releaseArtifactBytes, file_get_contents($directory.DIRECTORY_SEPARATOR.$artifactName.'.json'));
            @unlink($directory.DIRECTORY_SEPARATOR.$artifactName.'.json');
            @unlink($directory.DIRECTORY_SEPARATOR.$artifactName.'.proof.json');
            @rmdir($directory);
        }
    }
}
