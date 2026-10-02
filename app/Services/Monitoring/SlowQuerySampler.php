<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SamplingResult;

final class SlowQuerySampler implements SamplerInterface
{
    public function __construct(private readonly SamplerInterface $regularSampler) {}

    public function shouldSample(ContextInterface $parentContext, string $traceId, string $spanName, int $spanKind, AttributesInterface $attributes, array $links): SamplingResult
    {
        $sampler = $attributes->get('most.slow_query') === true ? new AlwaysOnSampler : $this->regularSampler;

        return $sampler->shouldSample($parentContext, $traceId, $spanName, $spanKind, $attributes, $links);
    }

    public function getDescription(): string
    {
        return 'SlowQuerySampler{'.$this->regularSampler->getDescription().'}';
    }
}
