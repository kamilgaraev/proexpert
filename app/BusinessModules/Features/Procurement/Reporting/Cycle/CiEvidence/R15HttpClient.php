<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence;

interface R15HttpClient
{
    /** @param list<string> $headers */
    public function get(string $url, array $headers = []): string;
}
