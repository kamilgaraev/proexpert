<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts;

use Illuminate\Http\Client\Response;

interface VisionResponseBodyReader
{
    public function read(Response $response, int $maxBytes): string;
}
