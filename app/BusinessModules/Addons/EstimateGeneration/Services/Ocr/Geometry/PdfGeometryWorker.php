<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\PdfGeometryExtractionException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class PdfGeometryWorker
{
    /**
     * @return array<string, mixed>
     */
    public function extract(string $content, ?string $filename = null): array
    {
        if ($content === '') {
            throw new PdfGeometryExtractionException('pdf_geometry_empty_content');
        }

        $scriptPath = $this->scriptPath();

        if (! is_file($scriptPath)) {
            throw new PdfGeometryExtractionException('pdf_geometry_script_missing', [
                'script_path' => $scriptPath,
            ]);
        }

        $inputPath = $this->temporaryPdfPath();
        $workspace = $this->temporaryWorkspace();
        file_put_contents($inputPath, $content);

        try {
            try {
                $process = new Process($this->command($inputPath, $filename, $workspace));
                $process->setTimeout(max(1, (int) config('estimate-generation.ocr.geometry.timeout_seconds', 45)));
                $process->run();
            } catch (ProcessTimedOutException $exception) {
                throw new PdfGeometryExtractionException('pdf_geometry_timeout', previous: $exception);
            } catch (Throwable $exception) {
                throw new PdfGeometryExtractionException('pdf_geometry_process_failed', previous: $exception);
            }
            if (! $process->isSuccessful()) {
                $stderr = trim($process->getErrorOutput());
                $stdout = trim($process->getOutput());

                throw new PdfGeometryExtractionException(
                    str_contains($stderr.$stdout, 'pymupdf_unavailable') ? 'pymupdf_unavailable' : 'pdf_geometry_process_failed',
                    [
                        'exit_code' => $process->getExitCode(),
                        'stderr' => mb_substr($stderr, 0, 1000),
                        'stdout' => mb_substr($stdout, 0, 1000),
                    ]
                );
            }

            $decoded = json_decode($process->getOutput(), true);
            if (! is_array($decoded)) {
                throw new PdfGeometryExtractionException('pdf_geometry_malformed_output', [
                    'output' => mb_substr($process->getOutput(), 0, 1000),
                ]);
            }

            return $this->embedPreviews($decoded, $workspace);
        } finally {
            if (is_file($inputPath)) {
                @unlink($inputPath);
            }
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * @return array<int, string>
     */
    private function command(string $inputPath, ?string $filename, string $workspace): array
    {
        $command = [
            (string) config('estimate-generation.ocr.geometry.python_binary', 'python'),
            $this->scriptPath(),
            '--input',
            $inputPath,
            '--filename',
            $filename ?: basename($inputPath),
            '--max-pages',
            (string) max(1, (int) config('estimate-generation.ocr.geometry.max_pages', 200)),
            '--max-vector-elements',
            (string) max(1, (int) config('estimate-generation.ocr.geometry.max_vector_elements', 5000)),
            '--workspace',
            $workspace,
            '--preview-dir',
            $workspace.DIRECTORY_SEPARATOR.'previews',
            '--render-preview',
        ];

        return $command;
    }

    private function temporaryWorkspace(): string
    {
        $workspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prohelper_pdf_preview_'.bin2hex(random_bytes(12));
        if (! mkdir($workspace, 0700, true) && ! is_dir($workspace)) {
            throw new PdfGeometryExtractionException('pdf_preview_workspace_failed');
        }

        return $workspace;
    }

    private function embedPreviews(array $payload, string $workspace): array
    {
        foreach ($payload['pages'] ?? [] as $index => $page) {
            $path = is_array($page) && is_array($page['preview'] ?? null) ? ($page['preview']['path'] ?? null) : null;
            if (! is_string($path) || ! is_file($path)) {
                throw new PdfGeometryExtractionException('pdf_preview_missing');
            }
            $real = realpath($path);
            if ($real === false || ! str_starts_with($real, realpath($workspace).DIRECTORY_SEPARATOR)) {
                throw new PdfGeometryExtractionException('pdf_preview_path_invalid');
            }
            $bytes = file_get_contents($real);
            if (! is_string($bytes) || $bytes === '' || strlen($bytes) > 20_000_000) {
                throw new PdfGeometryExtractionException('pdf_preview_invalid');
            }
            $payload['pages'][$index]['preview'] = [
                'content_base64' => base64_encode($bytes),
                'content_type' => 'image/png',
                'sha256' => hash('sha256', $bytes),
                'width' => $page['preview']['width'] ?? null,
                'height' => $page['preview']['height'] ?? null,
            ];
        }

        return $payload;
    }

    private function removeWorkspace(string $workspace): void
    {
        if (! is_dir($workspace)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($workspace, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($workspace);
    }

    private function scriptPath(): string
    {
        return (string) config(
            'estimate-generation.ocr.geometry.script_path',
            base_path('app/BusinessModules/Addons/EstimateGeneration/bin/pdf_geometry_extract.py')
        );
    }

    private function temporaryPdfPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'prohelper_pdf_geometry_');

        if ($path === false) {
            throw new PdfGeometryExtractionException('pdf_geometry_temp_file_failed');
        }

        $pdfPath = $path.'.pdf';

        if (! @rename($path, $pdfPath)) {
            return $path;
        }

        return $pdfPath;
    }
}
