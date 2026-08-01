<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use InvalidArgumentException;

final readonly class ReportPublicationReleaseTrustedRoots
{
    public string $bundleRoot;

    public string $candidateRoot;

    public function __construct(string $bundleRoot, string $candidateRoot)
    {
        $this->bundleRoot = $this->directory($bundleRoot);
        $this->candidateRoot = $this->directory($candidateRoot);
    }

    private function directory(string $directory): string
    {
        $resolved = realpath($directory);
        if ($directory === '' || ! is_string($resolved) || is_link($directory) || ! is_dir($resolved)) {
            throw new InvalidArgumentException('report_publication_release_trusted_root_invalid');
        }

        return $resolved;
    }
}
