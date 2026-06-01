<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Services\Contracts\DesignIfcToFragmentsConverterContract;
use RuntimeException;
use Symfony\Component\Process\Process;

final class DesignIfcToFragmentsConverter implements DesignIfcToFragmentsConverterContract
{
    public function convert(string $sourcePath, string $targetPath, callable $progress): void
    {
        if (!is_file($sourcePath)) {
            throw new RuntimeException('Source IFC file is not available.');
        }

        $scriptPath = base_path('resources/js/design-management/convert-ifc-to-frag.mjs');
        if (!is_file($scriptPath)) {
            throw new RuntimeException('IFC converter script is not available.');
        }

        $outputBuffer = '';
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
                $this->consumeProgressLine($line, $progress);
            }
        });

        $this->consumeProgressLine(trim($outputBuffer), $progress);

        if (!$process->isSuccessful()) {
            throw new RuntimeException('IFC converter failed.');
        }

        if (!is_file($targetPath) || filesize($targetPath) === 0) {
            throw new RuntimeException('IFC converter did not create a viewer file.');
        }
    }

    private function consumeProgressLine(string $line, callable $progress): void
    {
        if ($line === '') {
            return;
        }

        $payload = json_decode($line, true);
        if (!is_array($payload) || ($payload['event'] ?? null) !== 'progress') {
            return;
        }

        $progress($payload['progress'] ?? 0, (string) ($payload['stage'] ?? 'processing'));
    }
}
