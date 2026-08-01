<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence;

use RuntimeException;

final class StreamR15HttpClient implements R15HttpClient
{
    public function get(string $url, array $headers = []): string
    {
        $context = stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'timeout' => 10, 'ignore_errors' => false]]);
        $body = @file_get_contents($url, false, $context);
        if (! is_string($body)) {
            throw new RuntimeException('r15_candidate_evidence_oidc_untrusted');
        }

        return $body;
    }
}
