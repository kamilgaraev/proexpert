<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use InvalidArgumentException;

final readonly class ReportPublicationReleaseIssuerPreflight
{
    public function __construct(
        private ReportPublicationReleaseRequestFileLoader $requests,
        private ReportPublicationReleaseProfileCatalog $profiles,
    ) {}

    public function validate(string $requestFile, string $trustedRoot): ReportPublicationReleaseIssuerInput
    {
        $root = $this->trustedDirectory($trustedRoot);
        $request = $this->requests->load($requestFile, $root);
        $profile = $this->profiles->forCode($request->code);
        $profile->assertRequest($request);

        $canonicalRequest = $this->trustedFile($root, $profile->requestFileName());
        $resolvedRequest = realpath($requestFile);
        if (! is_string($resolvedRequest) || ! hash_equals($canonicalRequest, $resolvedRequest)) {
            throw new InvalidArgumentException('report_publication_release_input_invalid');
        }

        $artifactFiles = [];
        foreach ($profile->artifactPaths as $key => $path) {
            $artifactFiles[$key] = $this->trustedFile($root, $path);
        }

        return new ReportPublicationReleaseIssuerInput($root, $request, $profile, $artifactFiles);
    }

    private function trustedDirectory(string $directory): string
    {
        $resolved = realpath($directory);
        if (! is_string($resolved) || is_link($directory) || ! is_dir($resolved)) {
            throw new InvalidArgumentException('report_publication_release_trusted_root_untrusted');
        }

        return $resolved;
    }

    private function trustedFile(string $root, string $name): string
    {
        $path = $root.DIRECTORY_SEPARATOR.$name;
        $resolved = realpath($path);
        if (! is_string($resolved) || is_link($path) || ! is_file($resolved) || ! hash_equals($path, $resolved)) {
            throw new InvalidArgumentException('report_publication_release_trusted_root_incomplete');
        }

        return $resolved;
    }
}
