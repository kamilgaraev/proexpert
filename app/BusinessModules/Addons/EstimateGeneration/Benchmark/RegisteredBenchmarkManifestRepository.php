<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

final readonly class RegisteredBenchmarkManifestRepository
{
    /** @param array<string, array{locator: string, sha256: string}> $descriptors */
    public function __construct(private string $fixtureRoot, private array $descriptors) {}

    /** @return array{manifest: BenchmarkManifest, reference: string} */
    public function byLocator(string $locator): array
    {
        foreach ($this->descriptors as $reference => $descriptor) {
            if (($descriptor['locator'] ?? null) === $locator) {
                return ['manifest' => $this->load($reference, $descriptor), 'reference' => $reference];
            }
        }

        throw new BenchmarkManifestException('manifest_not_registered');
    }

    public function byReference(string $reference): BenchmarkManifest
    {
        $descriptor = $this->descriptors[$reference] ?? null;
        if (! is_array($descriptor)) {
            throw new BenchmarkManifestException('manifest_not_registered');
        }

        return $this->load($reference, $descriptor);
    }

    /** @param array{locator: string, sha256: string} $descriptor */
    private function load(string $reference, array $descriptor): BenchmarkManifest
    {
        $locator = $descriptor['locator'] ?? '';
        $sha256 = $descriptor['sha256'] ?? '';
        if (! preg_match('/^[a-z][a-z0-9._:-]{2,95}$/D', $reference)
            || ! preg_match('#^[a-zA-Z0-9._/-]+\.json$#D', $locator) || str_contains($locator, '..')
            || ! preg_match('/^[a-f0-9]{64}$/D', $sha256)) {
            throw new BenchmarkManifestException('manifest_descriptor_invalid');
        }
        $root = realpath($this->fixtureRoot);
        $candidate = $this->fixtureRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $locator);
        $path = realpath($candidate);
        $prefix = $root === false ? '' : rtrim(str_replace('\\', '/', $root), '/').'/';
        $size = $path === false ? false : @filesize($path);
        if ($root === false || $path === false || is_link($candidate)
            || ! str_starts_with(str_replace('\\', '/', $path), $prefix)
            || ! is_int($size) || $size < 2 || $size > 2_000_000
            || ! hash_equals($sha256, (string) hash_file('sha256', $path))) {
            throw new BenchmarkManifestException('manifest_integrity_failed');
        }

        return BenchmarkManifest::fromFile($path, $root, false);
    }
}
