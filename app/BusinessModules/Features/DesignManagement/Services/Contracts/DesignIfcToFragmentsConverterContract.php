<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services\Contracts;

interface DesignIfcToFragmentsConverterContract
{
    public function convert(string $sourcePath, string $targetPath, callable $progress): void;
}
