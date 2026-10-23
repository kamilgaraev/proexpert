<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Exceptions;

use JsonSerializable;
use RuntimeException;

final class AssistantEgressDenied extends RuntimeException implements JsonSerializable
{
    public readonly string $reason;

    public function __construct(string $reason = 'required_stage_unavailable')
    {
        $this->reason = in_array($reason, [
            'dependency_unavailable', 'auth_missing', 'access_denied', 'scope_mismatch',
            'source_stale', 'source_unknown', 'untrusted_creator', 'unknown_category',
            'required_stage_unavailable', 'fixture_mismatch', 'unsupported_purpose',
        ], true) ? $reason : 'required_stage_unavailable';
        parent::__construct('assistant_egress_denied');
    }

    public function jsonSerialize(): array
    {
        return ['error_code' => 'assistant_egress_denied'];
    }
}
