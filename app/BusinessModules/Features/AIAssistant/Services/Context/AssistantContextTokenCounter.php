<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Context;

use Closure;
use LogicException;

final readonly class AssistantContextTokenCounter
{
    public function __construct(private AssistantModelContextProfile $profile, private Closure $trustedTokenizer)
    {
    }

    public function count(array $payload): int
    {
        $identity = $this->profile->identity();
        $count = ($this->trustedTokenizer)(AssistantContextSourceBinding::canonical($payload), $identity);
        if (!is_array($count) || !AssistantModelContextProfile::hasExactKeys($count, [...array_keys($identity), 'tokens'])) {
            throw new LogicException('tokenizer_unavailable');
        }
        foreach ($identity as $key => $value) {
            if ($count[$key] !== $value) {
                throw new LogicException('tokenizer_identity_mismatch');
            }
        }
        if (!is_int($count['tokens']) || $count['tokens'] < 0) {
            throw new LogicException('tokenizer_invalid_count');
        }

        return $count['tokens'];
    }
}