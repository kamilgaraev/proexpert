<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Services\Contracts\DesignIfcToFragmentsConverterContract;
use App\BusinessModules\Features\DesignManagement\Support\DesignViewerConversionResult;
use RuntimeException;
use Symfony\Component\Process\Process;

final class DesignIfcToFragmentsConverter implements DesignIfcToFragmentsConverterContract
{
    public function convert(string $sourcePath, string $targetPath, callable $progress): DesignViewerConversionResult
    {
        if (!is_file($sourcePath)) {
            throw new RuntimeException('Source IFC file is not available.');
        }

        $scriptPath = base_path('resources/js/design-management/convert-ifc-to-frag.mjs');
        if (!is_file($scriptPath)) {
            throw new RuntimeException('IFC converter script is not available.');
        }

        $outputBuffer = '';
        $resultPayload = null;
        $process = new Process(
            [
                (string) config('design_management.viewer_converter_binary', 'node'),
                $scriptPath,
                $sourcePath,
                $targetPath,
            ],
            base_path(),
            null,
            null,
            (float) config('design_management.viewer_converter_timeout', 7200)
        );

        $process->run(function (string $type, string $buffer) use (&$outputBuffer, $progress): void {
            if ($type !== Process::OUT) {
                return;
            }

            $outputBuffer .= $buffer;

            while (($position = strpos($outputBuffer, "\n")) !== false) {
                $line = trim(substr($outputBuffer, 0, $position));
                $outputBuffer = substr($outputBuffer, $position + 1);
                $this->consumeOutputLine($line, $progress, $resultPayload);
            }
        });

        $this->consumeOutputLine(trim($outputBuffer), $progress, $resultPayload);

        if (!$process->isSuccessful()) {
            $errorOutput = trim($process->getErrorOutput());
            throw new RuntimeException($errorOutput === ''
                ? 'IFC converter failed.'
                : 'IFC converter failed: ' . $errorOutput);
        }

        if (!is_file($targetPath) || filesize($targetPath) === 0) {
            throw new RuntimeException('IFC converter did not create a viewer file.');
        }

        if (!is_array($resultPayload)) {
            throw new RuntimeException('IFC converter did not report viewer geometry metrics.');
        }

        $result = DesignViewerConversionResult::fromPayload($resultPayload);
        $result->assertRenderableGeometry();

        return $result;
    }

    private function consumeOutputLine(string $line, callable $progress, ?array &$resultPayload): void
    {
        if ($line === '') {
            return;
        }

        $payload = json_decode($line, true);
        if (!is_array($payload) || ($payload['event'] ?? null) !== 'progress') {
            if (is_array($payload) && ($payload['event'] ?? null) === 'result') {
                $resultPayload = $payload;
            }

            return;
        }

        $progress($payload['progress'] ?? 0, (string) ($payload['stage'] ?? 'processing'));
    }
}
