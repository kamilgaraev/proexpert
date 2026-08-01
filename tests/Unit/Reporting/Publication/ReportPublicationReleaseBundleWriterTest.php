<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseBundleWriter;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationReleaseBundle;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Reporting\Publication\ReportPublicationFixtureFactory;

final class ReportPublicationReleaseBundleWriterTest extends TestCase
{
    public function test_existing_artifact_name_is_never_overwritten(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-release-output-'.bin2hex(random_bytes(8));
        $proof = ReportPublicationFixtureFactory::eligible()['eligible']->proof;
        $artifactName = 'report-publication-release_one-'.str_repeat('a', 64);
        $bundle = new ReportPublicationReleaseBundle($proof, 'signed-artifact', $artifactName);
        $writer = new ReportPublicationReleaseBundleWriter;
        $writer->write($bundle, $directory);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('report_publication_release_output_exists');

            $writer->write(new ReportPublicationReleaseBundle($proof, 'replacement', $artifactName), $directory);
        } finally {
            self::assertSame('signed-artifact', file_get_contents($directory.DIRECTORY_SEPARATOR.$artifactName.'.json'));
            @unlink($directory.DIRECTORY_SEPARATOR.$artifactName.'.json');
            @unlink($directory.DIRECTORY_SEPARATOR.$artifactName.'.proof.json');
            @rmdir($directory);
        }
    }
}
