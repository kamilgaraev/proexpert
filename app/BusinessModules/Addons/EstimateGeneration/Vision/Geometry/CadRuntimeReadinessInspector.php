<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry;

use Symfony\Component\Process\Process;

final class CadRuntimeReadinessInspector
{
    public function assertReady(CadRuntimeConfiguration $configuration): void
    {
        $errors = $this->inspect($configuration);
        if ($errors !== []) {
            throw new \App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\GeometryExtractionException($errors[0]);
        }
    }

    /** @return array<int, string> */
    public function inspect(CadRuntimeConfiguration $configuration): array
    {
        $errors = [];
        foreach ([
            'python' => [$configuration->pythonBinary, true],
            'worker' => [$configuration->scriptPath, false],
            'dwgread' => [$configuration->dwgreadBinary, true],
            'sandbox' => [$configuration->sandboxBinary, true],
            'requirements' => [$configuration->requirementsLockPath, false],
        ] as $name => [$path, $executable]) {
            if (! $this->trustedFile($path, $executable)) {
                $errors[] = 'cad_'.$name.'_path_untrusted';
            } elseif ($configuration->enforceImmutability && $this->writableByRuntime($path)) {
                $errors[] = 'cad_'.$name.'_path_writable';
            }
        }
        if ($errors !== []) {
            return $errors;
        }
        if (! hash_equals($configuration->scriptSha256, (string) hash_file('sha256', $configuration->scriptPath))) {
            $errors[] = 'cad_worker_integrity_mismatch';
        }
        if (! hash_equals($configuration->requirementsSha256, (string) hash_file('sha256', $configuration->requirementsLockPath))) {
            $errors[] = 'cad_dependencies_integrity_mismatch';
        }
        $lock = (string) file_get_contents($configuration->requirementsLockPath);
        if (! str_contains($lock, '==') || ! str_contains($lock, '--hash=sha256:')) {
            $errors[] = 'cad_dependencies_not_pinned';
        }
        $process = new Process([$configuration->dwgreadBinary, '--version']);
        $process->setTimeout(5);
        $process->run();
        preg_match('/\bdwgread\s+(?<version>\d+\.\d+\.\d+)\b/', $process->getOutput().$process->getErrorOutput(), $version);
        if (! $process->isSuccessful() || ($version['version'] ?? null) !== $configuration->libredwgVersion) {
            $errors[] = 'cad_libredwg_version_mismatch';
        }

        return $errors;
    }

    private function writableByRuntime(string $path): bool
    {
        if (is_writable($path)) {
            return true;
        }
        $current = dirname($path);
        while ($current !== dirname($current)) {
            if (is_writable($current)) {
                return true;
            }
            $current = dirname($current);
        }

        return false;
    }

    private function trustedFile(string $path, bool $executable): bool
    {
        $windowsCommand = PHP_OS_FAMILY === 'Windows' && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['cmd', 'bat', 'exe'], true);
        if ($path === '' || ! is_file($path) || is_link($path) || ($executable && ! is_executable($path) && ! $windowsCommand)) {
            return false;
        }
        $real = realpath($path);
        if (PHP_OS_FAMILY === 'Windows') {
            $current = dirname($path);
            while ($current !== dirname($current)) {
                $check = new Process(['fsutil', 'reparsepoint', 'query', $current]);
                $check->run();
                if ($check->isSuccessful()) {
                    return false;
                }
                $current = dirname($current);
            }

            return is_string($real);
        }

        return is_string($real) && strcasecmp(str_replace('\\', '/', $real), str_replace('\\', '/', $path)) === 0;
    }
}
