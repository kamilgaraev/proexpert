<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseBundleFileLoader;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\Publication\ReportPublicationFixtureFactory;

final class ReportPublicationReleaseBundleFileLoaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'report-publication-bundle-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_loads_only_the_canonical_named_pair_from_the_trusted_directory(): void
    {
        $fixture = ReportPublicationFixtureFactory::eligible();
        $publication = $fixture['eligible'];
        $name = 'report-publication-'.$publication->candidate->code.'-'.$publication->proofHash->value;
        $proof = $this->directory.DIRECTORY_SEPARATOR.$name.'.proof.json';
        $artifact = $this->directory.DIRECTORY_SEPARATOR.$name.'.json';
        file_put_contents($proof, $publication->proof->canonicalBytes());
        file_put_contents($artifact, $publication->releaseArtifactBytes);

        $bundle = (new ReportPublicationReleaseBundleFileLoader)->load($proof, $artifact, $this->directory);

        self::assertSame($name, $bundle->artifactName);
        self::assertSame($publication->proofHash->value, $bundle->proof->digest()->value);
    }

    public function test_rejects_a_pair_with_a_noncanonical_proof_document(): void
    {
        $fixture = ReportPublicationFixtureFactory::eligible();
        $publication = $fixture['eligible'];
        $name = 'report-publication-'.$publication->candidate->code.'-'.$publication->proofHash->value;
        $proof = $this->directory.DIRECTORY_SEPARATOR.$name.'.proof.json';
        $artifact = $this->directory.DIRECTORY_SEPARATOR.$name.'.json';
        file_put_contents($proof, $publication->proof->canonicalBytes()."\n");
        file_put_contents($artifact, $publication->releaseArtifactBytes);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_proof_invalid');

        (new ReportPublicationReleaseBundleFileLoader)->load($proof, $artifact, $this->directory);
    }
}
