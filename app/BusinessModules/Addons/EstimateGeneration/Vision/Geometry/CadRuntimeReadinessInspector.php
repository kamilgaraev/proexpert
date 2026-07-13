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
            } elseif ($configuration->enforceImmutability) {
                if (! $this->insideTrustedRoot($name, $path)) {
                    $errors[] = 'cad_'.$name.'_root_untrusted';
                } elseif ($this->writableByRuntime($path)) {
                    $errors[] = 'cad_'.$name.'_path_writable';
                }
            }
        }
        if ($errors !== []) {
            return $errors;
        }
        if ($configuration->enforceImmutability) {
            $manifestErrors = $this->validateRuntimeManifest($configuration);
            if ($manifestErrors !== []) {
                return $manifestErrors;
            }
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
        try {
            $process = new Process([$configuration->dwgreadBinary, '--version']);
            $process->setTimeout(5);
            $process->run();
        } catch (\Throwable) {
            return [...$errors, 'cad_libredwg_version_unavailable'];
        }
        $output = trim($process->getOutput().$process->getErrorOutput());
        if (! $process->isSuccessful() || $output !== 'dwgread '.$configuration->libredwgVersion) {
            $errors[] = 'cad_libredwg_version_mismatch';
        }

        return $errors;
    }

    /** @return list<string> */
    private function validateRuntimeManifest(CadRuntimeConfiguration $configuration): array
    {
        if (! $this->trustedFile($configuration->runtimeHashManifest, false) || is_writable($configuration->runtimeHashManifest)) {
            return ['cad_runtime_manifest_untrusted'];
        }
        $lines = file($configuration->runtimeHashManifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (! is_array($lines)) {
            return ['cad_runtime_manifest_untrusted'];
        }
        $expected = [];
        foreach ($lines as $line) {
            if (preg_match('/^(?<hash>[a-f0-9]{64})  (?<path>\/.+)$/D', $line, $match) !== 1) {
                return ['cad_runtime_manifest_invalid'];
            }
            $expected[$match['path']] = $match['hash'];
        }
        foreach ([$configuration->pythonBinary, $configuration->scriptPath, $configuration->dwgreadBinary,
            $configuration->sandboxBinary, $configuration->requirementsLockPath] as $path) {
            $actual = hash_file('sha256', $path);
            if (! is_string($actual) || ! isset($expected[$path]) || ! hash_equals($expected[$path], $actual)) {
                return ['cad_runtime_artifact_integrity_mismatch'];
            }
        }

        return [];
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

    private function insideTrustedRoot(string $name, string $path): bool
    {
        $root = match ($name) {
            'python' => '/opt/geometry-venv/',
            'dwgread' => '/opt/libredwg/',
            'sandbox' => '/usr/local/bin/',
            'worker', 'requirements' => rtrim(str_replace('\\', '/', base_path()), '/').'/',
            default => '',
        };
        $real = realpath($path);

        return $root !== '' && is_string($real)
            && str_starts_with(str_replace('\\', '/', $real), $root);
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
