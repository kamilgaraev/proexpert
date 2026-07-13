<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\PdfGeometryExtractionException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class PdfGeometryWorker
{
    private const MAX_PREVIEW_PAGE_BYTES = 20_000_000;

    private const MAX_PREVIEW_PAGE_PIXELS = 25_000_000;

    private const MAX_PREVIEW_TOTAL_BYTES = 100_000_000;

    private const MAX_PREVIEW_TOTAL_PIXELS = 100_000_000;

    public function __construct(
        private readonly ?string $scriptPath = null,
        private readonly ?string $pythonBinary = null,
        private readonly ?int $timeoutSeconds = null,
        private readonly ?int $maxPages = null,
        private readonly ?int $maxVectorElements = null,
        private readonly ?int $maxPreviewTotalBytes = null,
        private readonly ?int $maxPreviewTotalPixels = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function extract(string $content, ?string $filename = null, ?callable $previewPublisher = null): array
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

        $workspace = $this->temporaryWorkspace();
        $inputPath = $workspace.DIRECTORY_SEPARATOR.'source.pdf';
        if (file_put_contents($inputPath, $content, LOCK_EX) !== strlen($content)) {
            $this->removeWorkspace($workspace);

            throw new PdfGeometryExtractionException('pdf_geometry_temp_file_failed');
        }

        try {
            try {
                $process = new Process($this->command($inputPath, $filename, $workspace));
                $process->setTimeout(max(1, $this->timeoutSeconds ?? (int) config('estimate-generation.ocr.geometry.timeout_seconds', 45)));
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

            if ($previewPublisher === null) {
                throw new PdfGeometryExtractionException('pdf_preview_publisher_required');
            }

            return $this->publishPreviews($decoded, $workspace, $previewPublisher);
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * @return array<int, string>
     */
    private function command(string $inputPath, ?string $filename, string $workspace): array
    {
        $command = [
            $this->pythonBinary ?? (string) config('estimate-generation.ocr.geometry.python_binary', 'python'),
            $this->scriptPath(),
            '--input',
            $inputPath,
            '--filename',
            $filename ?: basename($inputPath),
            '--max-pages',
            (string) max(1, $this->maxPages ?? (int) config('estimate-generation.ocr.geometry.max_pages', 200)),
            '--max-vector-elements',
            (string) max(1, $this->maxVectorElements ?? (int) config('estimate-generation.ocr.geometry.max_vector_elements', 5000)),
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

    private function publishPreviews(array $payload, string $workspace, callable $previewPublisher): array
    {
        $totalBytes = 0;
        $totalPixels = 0;
        $maxTotalBytes = min(
            self::MAX_PREVIEW_TOTAL_BYTES,
            max(1, $this->maxPreviewTotalBytes ?? $this->configInt('estimate-generation.ocr.geometry.max_preview_total_bytes', self::MAX_PREVIEW_TOTAL_BYTES)),
        );
        $maxTotalPixels = min(
            self::MAX_PREVIEW_TOTAL_PIXELS,
            max(1, $this->maxPreviewTotalPixels ?? $this->configInt('estimate-generation.ocr.geometry.max_preview_total_pixels', self::MAX_PREVIEW_TOTAL_PIXELS)),
        );
        foreach ($payload['pages'] ?? [] as $index => $page) {
            $path = is_array($page) && is_array($page['preview'] ?? null) ? ($page['preview']['path'] ?? null) : null;
            if (! is_string($path) || ! is_file($path)) {
                throw new PdfGeometryExtractionException('pdf_preview_missing');
            }
            $real = realpath($path);
            if ($real === false || ! str_starts_with($real, realpath($workspace).DIRECTORY_SEPARATOR)) {
                throw new PdfGeometryExtractionException('pdf_preview_path_invalid');
            }
            $width = $this->positiveInt($page['preview']['width'] ?? null);
            $height = $this->positiveInt($page['preview']['height'] ?? null);
            $pageNumber = $this->positiveInt($page['page_number'] ?? null);
            $size = filesize($real);
            if ($width === null || $height === null || $pageNumber === null || ! is_int($size) || $size < 1
                || $size > self::MAX_PREVIEW_PAGE_BYTES || $width * $height > self::MAX_PREVIEW_PAGE_PIXELS) {
                throw new PdfGeometryExtractionException('pdf_preview_invalid');
            }
            $totalBytes += $size;
            $totalPixels += $width * $height;
            if ($totalBytes > $maxTotalBytes) {
                throw new PdfGeometryExtractionException('pdf_preview_aggregate_bytes_limit');
            }
            if ($totalPixels > $maxTotalPixels) {
                throw new PdfGeometryExtractionException('pdf_preview_aggregate_pixels_limit');
            }
            $published = $previewPublisher($pageNumber, $real, ['width' => $width, 'height' => $height]);
            $payload['pages'][$index]['preview'] = $this->publishedPreview($published, $size, $width, $height);
        }

        return $payload;
    }

    private function publishedPreview(mixed $published, int $size, int $width, int $height): array
    {
        if (! is_array($published)
            || array_keys($published) !== ['artifact_path', 'content_type', 'sha256', 'bytes', 'width', 'height']
            || ! is_string($published['artifact_path']) || $published['artifact_path'] === ''
            || $published['content_type'] !== 'image/png'
            || ! is_string($published['sha256']) || preg_match('/^[a-f0-9]{64}$/D', $published['sha256']) !== 1
            || $published['bytes'] !== $size || $published['width'] !== $width || $published['height'] !== $height) {
            throw new PdfGeometryExtractionException('pdf_preview_artifact_invalid');
        }

        return $published;
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }

    private function configInt(string $key, int $default): int
    {
        try {
            return (int) config($key, $default);
        } catch (Throwable) {
            return $default;
        }
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
        if ($this->scriptPath !== null) {
            return $this->scriptPath;
        }

        return (string) config(
            'estimate-generation.ocr.geometry.script_path',
            base_path('app/BusinessModules/Addons/EstimateGeneration/bin/pdf_geometry_extract.py')
        );
    }
}
