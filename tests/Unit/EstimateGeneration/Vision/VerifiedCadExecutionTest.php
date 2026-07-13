<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\GeometryExtractionException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\GeometryProcessRunner;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\VerifiedCadExecution;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VerifiedCadExecutionTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function artifacts(): array
    {
        return [
            'python executable' => ['python'],
            'dwgread executable' => ['dwgread'],
            'sandbox executable' => ['sandbox'],
            'worker script' => ['worker'],
            'requirements lock' => ['requirements'],
        ];
    }

    #[Test]
    #[DataProvider('artifacts')]
    public function artifact_mutation_is_rejected_before_process_start(string $mutated): void
    {
        $root = sys_get_temp_dir().'/most-verified-cad-'.bin2hex(random_bytes(6));
        mkdir($root);
        $marker = $root.'/started';
        $suffix = PHP_OS_FAMILY === 'Windows' ? '.cmd' : '';
        $paths = [];
        foreach (['python', 'dwgread', 'sandbox', 'worker', 'requirements'] as $name) {
            $paths[$name] = $root.'/'.$name.($name === 'python' ? $suffix : '');
            $body = $name === 'python'
                ? (PHP_OS_FAMILY === 'Windows' ? "@echo started>\"$marker\"\r\n" : "#!/bin/sh\necho started > '$marker'\n")
                : $name.'-v1';
            file_put_contents($paths[$name], $body);
            if ($name === 'python') {
                chmod($paths[$name], 0755);
            }
        }
        $hashes = array_combine(array_values($paths), array_map(static fn (string $path): string => (string) hash_file('sha256', $path), array_values($paths)));
        file_put_contents($paths[$mutated], $mutated.'-v2');

        try {
            (new GeometryProcessRunner('Windows'))->runVerified(
                new VerifiedCadExecution([$paths['python']], $hashes), $root, 2, 1024,
            );
            self::fail('Mutated artifact reached process start.');
        } catch (GeometryExtractionException $exception) {
            self::assertSame('cad_runtime_artifact_integrity_mismatch', $exception->reason);
            self::assertFileDoesNotExist($marker);
        } finally {
            foreach ($paths as $path) {
                @unlink($path);
            }
            if (is_file($marker)) {
                unlink($marker);
            }
            @rmdir($root);
        }
    }

    #[Test]
    public function process_start_count_does_not_grow_after_post_verification_mutation(): void
    {
        $root = sys_get_temp_dir().'/most-verified-window-'.bin2hex(random_bytes(6));
        mkdir($root);
        $marker = $root.'/starts';
        $python = $root.'/python'.(PHP_OS_FAMILY === 'Windows' ? '.cmd' : '');
        $worker = $root.'/worker.py';
        file_put_contents($python, PHP_OS_FAMILY === 'Windows' ? "@echo started>>\"$marker\"\r\n" : "#!/bin/sh\necho started >> '$marker'\n");
        chmod($python, 0755);
        file_put_contents($worker, 'v1');
        $execution = new VerifiedCadExecution([$python], [
            $python => (string) hash_file('sha256', $python),
            $worker => (string) hash_file('sha256', $worker),
        ]);
        putenv('APP_ENV=testing');
        $runner = new GeometryProcessRunner('Windows');
        try {
            self::assertSame(0, $runner->runVerified($execution, $root, 2, 1024)['exit_code']);
            file_put_contents($worker, 'v2');
            try {
                $runner->runVerified($execution, $root, 2, 1024);
                self::fail('Second process start must be rejected.');
            } catch (GeometryExtractionException $exception) {
                self::assertSame('cad_runtime_artifact_integrity_mismatch', $exception->reason);
            }
            self::assertCount(1, file($marker, FILE_IGNORE_NEW_LINES));
        } finally {
            @unlink($python);
            @unlink($worker);
            @unlink($marker);
            @rmdir($root);
        }
    }
}
