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
        file_put_contents($inputPath, $content);

        try {
            $process = new Process($this->command($inputPath, $filename));
            $process->setTimeout(max(1, (int) config('estimate-generation.ocr.geometry.timeout_seconds', 45)));
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            throw new PdfGeometryExtractionException('pdf_geometry_timeout', previous: $exception);
        } catch (Throwable $exception) {
            throw new PdfGeometryExtractionException('pdf_geometry_process_failed', previous: $exception);
        } finally {
            if (is_file($inputPath)) {
                @unlink($inputPath);
            }
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

        return $decoded;
    }

    /**
     * @return array<int, string>
     */
    private function command(string $inputPath, ?string $filename): array
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
        ];

        if ((bool) config('estimate-generation.ocr.geometry.render_previews', false)) {
            $command[] = '--render-preview';
            $command[] = '--preview-dir';
            $command[] = (string) config('estimate-generation.ocr.geometry.preview_dir', storage_path('app/estimate-generation/pdf-previews'));
        }

        return $command;
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
