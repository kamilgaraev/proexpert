<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseRequestFileLoader;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReportPublicationReleaseRequestFileLoaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-release-request-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->directory);
    }

    public function test_loads_exact_non_executable_request_contract(): void
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.'release_one.json';
        file_put_contents($path, '{"request_id":"release_one","schema_version":"1.0.0"}');

        $request = (new ReportPublicationReleaseRequestFileLoader)->load($path, $this->directory);

        self::assertSame('release_one', $request->requestId);
    }

    public function test_rejects_symlink_even_when_target_is_inside_trusted_directory(): void
    {
        $target = $this->directory.DIRECTORY_SEPARATOR.'release_one.json';
        $link = $this->directory.DIRECTORY_SEPARATOR.'release_two.json';
        file_put_contents($target, '{"request_id":"release_one","schema_version":"1.0.0"}');
        if (! @symlink($target, $link)) {
            $source = file_get_contents(
                dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/Application/Publication/'
                .'ReportPublicationReleaseRequestFileLoader.php',
            );
            self::assertIsString($source);
            self::assertStringContainsString('is_link($requestFile)', $source);

            return;
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_release_input_invalid');

        (new ReportPublicationReleaseRequestFileLoader)->load($link, $this->directory);
    }

    public function test_rejects_filename_and_request_identifier_mismatch(): void
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.'release_two.json';
        file_put_contents($path, '{"request_id":"release_one","schema_version":"1.0.0"}');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_release_input_invalid');

        (new ReportPublicationReleaseRequestFileLoader)->load($path, $this->directory);
    }

    public function test_rejects_extra_request_fields(): void
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.'release_one.json';
        file_put_contents(
            $path,
            '{"request_id":"release_one","schema_version":"1.0.0","admission":"Tests\\\\Fixture"}',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_release_request_invalid');

        (new ReportPublicationReleaseRequestFileLoader)->load($path, $this->directory);
    }
}
