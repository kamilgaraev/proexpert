<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry;

final readonly class GeometryResourceLimits
{
    public function __construct(
        public int $memoryLimitKiB = 524_288,
        public int $cpuLimitSeconds = 45,
        public int $fileSizeLimitBytes = 16_777_216,
        public int $openFileLimit = 64,
    ) {
        if ($this->memoryLimitKiB < 65_536 || $this->memoryLimitKiB > 4_194_304
            || $this->cpuLimitSeconds < 1 || $this->cpuLimitSeconds > 600
            || $this->fileSizeLimitBytes < 4096 || $this->fileSizeLimitBytes > 268_435_456
            || $this->openFileLimit < 16 || $this->openFileLimit > 1024) {
            throw new \InvalidArgumentException('geometry_resource_limits_invalid');
        }
    }

    /** @return array{string, string, string, string} */
    public function sandboxArguments(): array
    {
        return [
            (string) $this->memoryLimitKiB,
            (string) $this->cpuLimitSeconds,
            (string) ((int) ceil($this->fileSizeLimitBytes / 512)),
            (string) $this->openFileLimit,
        ];
    }
}
