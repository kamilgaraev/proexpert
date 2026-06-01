<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services\Contracts;

use App\BusinessModules\Features\DesignManagement\Support\DesignViewerConversionResult;

interface DesignIfcToFragmentsConverterContract
{
    public function convert(string $sourcePath, string $targetPath, callable $progress): DesignViewerConversionResult;
}
