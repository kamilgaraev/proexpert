<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry;

final readonly class CadRuntimeConfiguration
{
    public function __construct(
        public string $pythonBinary,
        public string $scriptPath,
        public string $dwgreadBinary,
        public string $libredwgVersion,
        public string $sandboxBinary,
        public string $requirementsLockPath,
        public string $scriptSha256,
        public string $requirementsSha256,
        public int $timeoutSeconds,
        public int $maxInputBytes,
        public int $maxOutputBytes,
        public int $maxEntities,
        public int $memoryLimitKiB,
        public int $cpuLimitSeconds,
        public int $fileSizeLimitBytes,
        public int $openFileLimit,
    ) {}

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values, bool $production): self
    {
        $defaults = [
            'python_binary' => PHP_OS_FAMILY === 'Windows' ? 'python' : '/usr/bin/python3',
            'script_path' => dirname(__DIR__, 2).'/bin/cad_geometry_extract.py',
            'dwgread_binary' => PHP_OS_FAMILY === 'Windows' ? 'dwgread.exe' : '/opt/libredwg/bin/dwgread',
            'libredwg_version' => '0.13.4',
            'sandbox_binary' => PHP_OS_FAMILY === 'Linux' ? '/usr/local/bin/geometry-sandbox' : '',
            'requirements_lock_path' => dirname(__DIR__, 7).'/docker/geometry/requirements.lock',
            'script_sha256' => '',
            'requirements_sha256' => '',
            'timeout_seconds' => 45,
            'max_input_bytes' => 52_428_800,
            'max_output_bytes' => 16_777_216,
            'max_entities' => 250_000,
            'memory_limit_kib' => 524_288,
            'cpu_limit_seconds' => 45,
            'file_size_limit_bytes' => 16_777_216,
            'open_file_limit' => 64,
        ];
        $config = array_replace($defaults, $values);
        if ($production) {
            foreach (['python_binary', 'script_path', 'dwgread_binary', 'sandbox_binary', 'requirements_lock_path'] as $key) {
                if (! self::absolute((string) $config[$key])) {
                    throw new \InvalidArgumentException('cad_'.str_replace(['_binary', '_path'], '', $key).'_path_invalid');
                }
            }
            foreach (['script_sha256', 'requirements_sha256'] as $key) {
                if (preg_match('/^[a-f0-9]{64}$/D', (string) $config[$key]) !== 1) {
                    throw new \InvalidArgumentException('cad_integrity_config_invalid');
                }
            }
        }
        foreach (['timeout_seconds', 'max_input_bytes', 'max_output_bytes', 'max_entities', 'memory_limit_kib', 'cpu_limit_seconds', 'file_size_limit_bytes', 'open_file_limit'] as $key) {
            if ((int) $config[$key] < 1) {
                throw new \InvalidArgumentException('cad_limit_invalid');
            }
        }

        return new self(
            (string) $config['python_binary'], (string) $config['script_path'], (string) $config['dwgread_binary'],
            (string) $config['libredwg_version'], (string) $config['sandbox_binary'], (string) $config['requirements_lock_path'],
            (string) $config['script_sha256'], (string) $config['requirements_sha256'], (int) $config['timeout_seconds'],
            (int) $config['max_input_bytes'], (int) $config['max_output_bytes'], (int) $config['max_entities'],
            (int) $config['memory_limit_kib'], (int) $config['cpu_limit_seconds'], (int) $config['file_size_limit_bytes'],
            (int) $config['open_file_limit'],
        );
    }

    private static function absolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1;
    }
}
