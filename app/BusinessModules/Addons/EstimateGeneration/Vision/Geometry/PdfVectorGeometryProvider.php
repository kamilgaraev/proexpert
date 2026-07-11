<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VectorGeometryData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\GeometryExtractionException;
use Symfony\Component\Process\Process;

final readonly class PdfVectorGeometryProvider
{
    public function __construct(
        private string $pythonBinary = 'python3',
        private string $scriptPath = '',
        private int $timeoutSeconds = 45,
        private int $maxInputBytes = 52_428_800,
        private int $maxOutputBytes = 16_777_216,
    ) {}

    public function extractLocal(string $inputPath): VectorGeometryData
    {
        $real = realpath($inputPath);
        if ($real === false || is_link($inputPath) || ! is_file($real)) {
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
            $process = new Process([$this->pythonBinary, $script, '--input', $copy, '--workspace', $workspace, '--contract-vector']);
            $process->setTimeout($this->timeoutSeconds);
            $process->setIdleTimeout(min(15, $this->timeoutSeconds));
            $process->run();
            if (strlen($process->getOutput()) > $this->maxOutputBytes || strlen($process->getErrorOutput()) > 8192) {
                throw new GeometryExtractionException('pdf_output_oversize');
            }
            if (! $process->isSuccessful()) {
                $error = json_decode($process->getErrorOutput(), true);
                throw new GeometryExtractionException(is_array($error) && is_string($error['code'] ?? null) ? $error['code'] : 'pdf_runtime_failed');
            }
            $data = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);

            return VectorGeometryData::fromArray($data);
        } catch (GeometryExtractionException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new GeometryExtractionException('pdf_contract_invalid');
        } finally {
            @unlink($copy);
            @rmdir($workspace);
        }
    }
}
