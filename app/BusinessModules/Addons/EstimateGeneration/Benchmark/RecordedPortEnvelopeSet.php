<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

final readonly class RecordedPortEnvelopeSet
{
    /** @param array<string, RecordedPortEnvelope> $envelopes */
    public function __construct(private array $envelopes) {}

    public function require(RecordedPort $port): RecordedPortEnvelope
    {
        return $this->envelopes[$port->value]
            ?? throw new RecordedPortEnvelopeException('recorded_port_missing');
    }

    /** @return list<RecordedPort> */
    public function ports(): array
    {
        return array_map(static fn (RecordedPortEnvelope $item): RecordedPort => $item->port, array_values($this->envelopes));
    }
}
