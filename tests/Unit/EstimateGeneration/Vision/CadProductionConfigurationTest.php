<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\CadRuntimeConfiguration;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\CadRuntimeReadinessInspector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CadProductionConfigurationTest extends TestCase
{
    #[Test]
    public function production_configuration_rejects_relative_runtime_paths(): void
    {
        $this->expectExceptionMessage('cad_python_path_invalid');

        CadRuntimeConfiguration::fromArray(['python_binary' => 'python3'], true);
    }

    #[Test]
    public function readiness_rejects_symlink_before_executing_it(): void
    {
        $root = sys_get_temp_dir().'/most-cad-readiness-'.bin2hex(random_bytes(6));
        mkdir($root);
        $target = $root.'/target';
        $link = $root.'/link';
        mkdir($target);
        $binaryName = PHP_OS_FAMILY === 'Windows' ? 'python.cmd' : 'python';
        $binary = $this->executable($target.'/'.$binaryName, 'unexpected');
        if (PHP_OS_FAMILY === 'Windows') {
            exec('cmd /c mklink /J '.escapeshellarg(str_replace('/', '\\', $link)).' '.escapeshellarg(str_replace('/', '\\', $target)), $output, $status);
            self::assertSame(0, $status, 'Windows junction creation failed.');
        } else {
            self::assertTrue(symlink($target, $link));
        }

        try {
            $result = (new CadRuntimeReadinessInspector)->inspect($this->configuration($root, $link.'/'.$binaryName));
            self::assertContains('cad_python_path_untrusted', $result);
            self::assertFileDoesNotExist($root.'/executed');
        } finally {
            if (PHP_OS_FAMILY === 'Windows') {
                @rmdir($link);
            } else {
                @unlink($link);
            }
            @unlink($binary);
            @rmdir($target);
            @rmdir($root);
        }
    }

    #[Test]
    public function readiness_reports_exact_libredwg_version_mismatch_privately(): void
    {
        $root = sys_get_temp_dir().'/most-cad-readiness-'.bin2hex(random_bytes(6));
        mkdir($root);
        $suffix = PHP_OS_FAMILY === 'Windows' ? '.cmd' : '';
        $python = $this->executable($root.'/python'.$suffix, 'Python 3.13.0');
        $dwgread = $this->executable($root.'/dwgread'.$suffix, 'dwgread 0.13.3');
        $sandbox = $this->executable($root.'/sandbox'.$suffix, 'sandbox');
        $script = $root.'/worker.py';
        file_put_contents($script, 'print("ok")');
        $lock = $root.'/requirements.lock';
        file_put_contents($lock, 'ezdxf==1.4.4 --hash=sha256:abc');

        try {
            $result = (new CadRuntimeReadinessInspector)->inspect(new CadRuntimeConfiguration(
                $python, $script, $dwgread, '0.13.4', $sandbox, $lock,
                hash_file('sha256', $script), hash_file('sha256', $lock), 45, 1024, 1024, 10, 1024, 1, 1024, 8,
            ));
            self::assertContains('cad_libredwg_version_mismatch', $result);
            self::assertStringNotContainsString($root, implode(' ', $result));
        } finally {
            foreach ([$python, $dwgread, $sandbox, $script, $lock] as $path) {
                @unlink($path);
            }
            @rmdir($root);
        }
    }

    private function configuration(string $root, string $python): CadRuntimeConfiguration
    {
        return new CadRuntimeConfiguration(
            $python, $root.'/worker.py', $root.'/dwgread', '0.13.4', $root.'/sandbox', $root.'/requirements.lock',
            str_repeat('a', 64), str_repeat('b', 64), 45, 1024, 1024, 10, 1024, 1, 1024, 8,
        );
    }

    private function executable(string $path, string $output): string
    {
        file_put_contents($path, PHP_OS_FAMILY === 'Windows' ? "@echo $output\r\n" : "#!/bin/sh\necho '$output'\n");
        chmod($path, 0755);

        return $path;
    }
}
