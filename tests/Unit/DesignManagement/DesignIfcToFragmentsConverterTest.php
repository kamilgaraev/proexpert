<?php

declare(strict_types=1);

namespace Tests\Unit\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Services\DesignIfcToFragmentsConverter;
use Tests\TestCase;

final class DesignIfcToFragmentsConverterTest extends TestCase
{
    public function test_converter_returns_geometry_metrics_emitted_by_process(): void
    {
        $binaryPath = $this->fakeConverterBinary();
        config([
            'design_management.viewer_converter_binary' => $binaryPath,
            'design_management.viewer_converter_timeout' => 10,
        ]);

        $sourcePath = $this->temporaryPath('ifc');
        $targetPath = $this->temporaryPath('frag');
        file_put_contents($sourcePath, 'IFC source');
        @unlink($targetPath);

        $progressEvents = [];
        $result = (new DesignIfcToFragmentsConverter())->convert(
            $sourcePath,
            $targetPath,
            static function (mixed $progress, string $stage) use (&$progressEvents): void {
                $progressEvents[] = [$progress, $stage];
            }
        );

        $metadata = $result->metadata();

        $this->assertSame('fragment binary', file_get_contents($targetPath));
        $this->assertSame([[45, 'converting']], $progressEvents);
        $this->assertSame('geometry_first_stage_one', $metadata['profile']);
        $this->assertSame(2, $metadata['geometry']['local_id_count']);
        $this->assertSame(2, $metadata['geometry']['sample_count']);
        $this->assertSame(1, $metadata['geometry']['representation_count']);
        $this->assertSame(['x' => 0.0, 'y' => 0.0, 'z' => 0.0], $metadata['geometry']['bounding_box']['min']);
    }

    private function fakeConverterBinary(): string
    {
        $extension = PHP_OS_FAMILY === 'Windows' ? 'cmd' : 'sh';
        $path = $this->temporaryPath($extension);
        $resultPayload = '{"event":"result","metrics":{"format":"thatopen_frag","profile":"geometry_first_stage_one","local_id_count":2,"category_count":1,"sample_count":2,"representation_count":1,"shell_count":1,"bounding_box":{"min":{"x":0,"y":0,"z":0},"max":{"x":1,"y":1,"z":1}}}}';
        $progressPayload = '{"event":"progress","progress":45,"stage":"converting"}';

        if (PHP_OS_FAMILY === 'Windows') {
            file_put_contents($path, implode("\r\n", [
                '@echo off',
                'set "TARGET=%3"',
                '> "%TARGET%" <nul set /p "=fragment binary"',
                'echo ' . $progressPayload,
                'echo ' . $resultPayload,
                'exit /b 0',
                '',
            ]));
        } else {
            file_put_contents($path, implode("\n", [
                '#!/usr/bin/env sh',
                'target="$3"',
                'printf "%s" "fragment binary" > "$target"',
                "printf '%s\\n' " . escapeshellarg($progressPayload),
                "printf '%s\\n' " . escapeshellarg($resultPayload),
                '',
            ]));
            chmod($path, 0755);
        }

        return $path;
    }

    private function temporaryPath(string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'design-ifc-converter-');
        $targetPath = $path . '.' . $extension;
        rename($path, $targetPath);

        return $targetPath;
    }
}
