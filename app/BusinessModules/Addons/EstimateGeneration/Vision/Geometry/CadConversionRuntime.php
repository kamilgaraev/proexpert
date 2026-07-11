<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VectorGeometryData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\GeometryExtractionException;

final readonly class CadConversionRuntime
{
    public function __construct(
        private string $pythonBinary = 'python3',
        private string $scriptPath = '',
        private string $dwgreadBinary = 'dwgread',
        private int $timeoutSeconds = 45,
        private int $maxInputBytes = 52_428_800,
        private int $maxOutputBytes = 16_777_216,
        private ?GeometryResourceLimits $resourceLimits = null,
    ) {}

    public function extract(string $inputPath): VectorGeometryData
    {
        $real = realpath($inputPath);
        if ($real === false || ! is_file($real) || is_link($inputPath) || $this->resolvesThroughIndirectPath($inputPath, $real)) {
            throw new GeometryExtractionException('cad_source_invalid');
        }
        $size = filesize($real);
        if (! is_int($size) || $size < 1 || $size > $this->maxInputBytes) {
            throw new GeometryExtractionException('cad_size_invalid');
        }
        $extension = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $prefix = file_get_contents($real, false, null, 0, 32);
        if (! $this->signatureMatches($extension, (string) $prefix)) {
            throw new GeometryExtractionException('cad_signature_mismatch');
        }
        $script = $this->scriptPath !== '' ? $this->scriptPath : dirname(__DIR__, 2).'/bin/cad_geometry_extract.py';
        $workDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-cad-'.bin2hex(random_bytes(12));
        if (! mkdir($workDir, 0700)) {
            throw new GeometryExtractionException('cad_workspace_failed');
        }
        $copy = $workDir.DIRECTORY_SEPARATOR.'source.'.$extension;
        try {
            if (! copy($real, $copy)) {
                throw new GeometryExtractionException('cad_source_copy_failed');
            }
            $result = (new GeometryProcessRunner)->run(
                [$this->pythonBinary, $script, '--input', $copy, '--workspace', $workDir,
                    '--dwgread', $this->dwgreadBinary, '--max-output-bytes', (string) $this->maxOutputBytes],
                $workDir,
                'cad',
                $this->timeoutSeconds,
                $this->maxOutputBytes,
                resourceLimits: $this->resourceLimits,
            );
            $stdout = $result['stdout'];
            $stderr = $result['stderr'];
            if ($result['exit_code'] !== 0) {
                $error = json_decode($stderr, true);
                throw new GeometryExtractionException(
                    is_array($error) && is_string($error['code'] ?? null) ? $error['code'] : 'cad_runtime_failed',
                    is_array($error) && ($error['retryable'] ?? false) === true,
                    is_array($error) && is_array($error['context'] ?? null) ? $error['context'] : [],
                );
            }
            $decoded = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new GeometryExtractionException('cad_runtime_contract_invalid');
            }

            return VectorGeometryData::fromArray($decoded);
        } catch (GeometryExtractionException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new GeometryExtractionException('cad_runtime_contract_invalid');
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    private function signatureMatches(string $extension, string $prefix): bool
    {
        return match ($extension) {
            'dwg' => preg_match('/^AC10(?:09|12|14|15|18|21|24|27|32|34)/D', $prefix) === 1,
            'dxf' => str_contains($prefix, 'SECTION') || str_starts_with($prefix, 'AutoCAD Binary DXF'),
            default => false,
        };
    }

    private function resolvesThroughIndirectPath(string $inputPath, string $realPath): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return $this->containsWindowsReparsePoint($inputPath);
        }
        if (! str_starts_with($inputPath, DIRECTORY_SEPARATOR)
            && preg_match('/^[A-Za-z]:[\\\\\/]/', $inputPath) !== 1) {
            return false;
        }

        return strcasecmp(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $inputPath), str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $realPath)) !== 0;
    }

    private function containsWindowsReparsePoint(string $path): bool
    {
        $directory = dirname($path);
        while ($directory !== dirname($directory)) {
            $process = new \Symfony\Component\Process\Process(['fsutil', 'reparsepoint', 'query', $directory]);
            $process->run();
            if ($process->isSuccessful()) {
                return true;
            }
            $directory = dirname($directory);
        }

        return false;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $item) {
            $item->isDir() && ! $item->isLink() ? $this->removeDirectory($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
