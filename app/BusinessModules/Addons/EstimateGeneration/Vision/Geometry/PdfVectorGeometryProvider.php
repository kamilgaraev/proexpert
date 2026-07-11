<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VectorGeometryData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\GeometryExtractionException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing\BoundedStorageReader;
use App\Models\Organization;
use App\Services\Storage\FileService;

final readonly class PdfVectorGeometryProvider
{
    public function __construct(
        private string $pythonBinary = 'python3',
        private string $scriptPath = '',
        private int $timeoutSeconds = 45,
        private int $maxInputBytes = 52_428_800,
        private int $maxOutputBytes = 16_777_216,
        private ?FileService $fileService = null,
        private ?BoundedStorageReader $reader = null,
    ) {}

    public function extract(string $storageKey, Organization $organization): VectorGeometryData
    {
        if (! str_starts_with($storageKey, 'org-'.$organization->getKey().'/')
            || str_contains($storageKey, '..')
            || strtolower(pathinfo($storageKey, PATHINFO_EXTENSION)) !== 'pdf') {
            throw new GeometryExtractionException('pdf_storage_scope_invalid');
        }
        if ($this->fileService === null || $this->reader === null) {
            throw new GeometryExtractionException('pdf_storage_provider_unavailable');
        }
        try {
            $content = $this->reader->read($this->fileService->disk($organization), $storageKey, $this->maxInputBytes);
        } catch (\Throwable) {
            throw new GeometryExtractionException('pdf_source_read_failed', true);
        }
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-pdf-source-'.bin2hex(random_bytes(12));
        if (! mkdir($directory, 0700)) {
            throw new GeometryExtractionException('pdf_workspace_failed');
        }
        $path = $directory.DIRECTORY_SEPARATOR.'source.pdf';
        try {
            if (file_put_contents($path, $content, LOCK_EX) !== strlen($content)) {
                throw new GeometryExtractionException('pdf_source_copy_failed');
            }

            return $this->extractLocal($path);
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function extractLocal(string $inputPath): VectorGeometryData
    {
        $real = realpath($inputPath);
        if ($real === false || is_link($inputPath) || ! is_file($real) || $this->resolvesThroughIndirectPath($inputPath, $real)) {
            throw new GeometryExtractionException('pdf_source_invalid');
        }
        $size = filesize($real);
        $magic = file_get_contents($real, false, null, 0, 5);
        if (! is_int($size) || $size < 1 || $size > $this->maxInputBytes || $magic !== '%PDF-') {
            throw new GeometryExtractionException('pdf_signature_mismatch');
        }
        $workspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-pdf-'.bin2hex(random_bytes(12));
        mkdir($workspace, 0700);
        $copy = $workspace.DIRECTORY_SEPARATOR.'source.pdf';
        try {
            copy($real, $copy);
            $script = $this->scriptPath !== '' ? $this->scriptPath : dirname(__DIR__, 2).'/bin/pdf_geometry_extract.py';
            $result = (new GeometryProcessRunner)->run(
                [$this->pythonBinary, $script, '--input', $copy, '--workspace', $workspace, '--contract-vector'],
                $workspace,
                'pdf',
                $this->timeoutSeconds,
                $this->maxOutputBytes,
            );
            if ($result['exit_code'] !== 0) {
                $error = json_decode($result['stderr'], true);
                throw new GeometryExtractionException(is_array($error) && is_string($error['code'] ?? null) ? $error['code'] : 'pdf_runtime_failed');
            }
            $data = json_decode($result['stdout'], true, 32, JSON_THROW_ON_ERROR);

            return VectorGeometryData::fromArray($data);
        } catch (GeometryExtractionException $exception) {
            throw $exception;
        } catch (\InvalidArgumentException $exception) {
            throw new GeometryExtractionException($exception->getMessage());
        } catch (\Throwable) {
            throw new GeometryExtractionException('pdf_contract_invalid');
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $item) {
            $item->isDir() && ! $item->isLink()
                ? $this->removeDirectory($item->getPathname())
                : @unlink($item->getPathname());
        }
        @rmdir($directory);
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
}
