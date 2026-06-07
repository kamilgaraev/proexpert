<?php

declare(strict_types=1);

namespace App\Services\OneCExchange;

use function trans_message;

final class OneCExchangeRunbookService
{
    private const SCENARIOS = [
        'transport_unavailable' => [
            'severity' => 'critical',
            'signals' => ['transport_error', 'timeout', 'server_error'],
            'prohelper_checks' => ['monitoring', 'journal', 'retry_window'],
            'handoff_to_1c' => ['operation', 'safe_error', 'time_window'],
        ],
        'dead_letter' => [
            'severity' => 'critical',
            'signals' => ['status', 'attempts', 'retry_disabled'],
            'prohelper_checks' => ['operation_detail', 'messages', 'source_actuality'],
            'handoff_to_1c' => ['operation', 'safe_error', 'document'],
        ],
        'requires_mapping' => [
            'severity' => 'warning',
            'signals' => ['status', 'safe_code', 'scope'],
            'prohelper_checks' => ['mapping_registry', 'duplicates', 'local_object'],
            'handoff_to_1c' => ['external_id', 'external_name', 'scope'],
        ],
        'stale_processing' => [
            'severity' => 'critical',
            'signals' => ['status', 'started_at', 'timeout'],
            'prohelper_checks' => ['worker', 'last_attempt', 'journal'],
            'handoff_to_1c' => ['operation', 'last_request', 'time_window'],
        ],
        'overdue_retry' => [
            'severity' => 'warning',
            'signals' => ['status', 'next_retry_at', 'retry_count'],
            'prohelper_checks' => ['worker', 'queue', 'next_retry'],
            'handoff_to_1c' => ['operation', 'safe_error', 'retry_history'],
        ],
        'business_validation_rejected' => [
            'severity' => 'warning',
            'signals' => ['status', 'business_validation', 'rejected'],
            'prohelper_checks' => ['safe_error', 'source_data', 'mapping'],
            'handoff_to_1c' => ['business_rule', 'document', 'safe_error'],
        ],
        'delivery_unconfigured' => [
            'severity' => 'critical',
            'signals' => ['disabled', 'unconfigured', 'token'],
            'prohelper_checks' => ['status', 'tokens', 'delivery_config'],
            'handoff_to_1c' => ['endpoint', 'token_status', 'network'],
        ],
    ];

    public function items(): array
    {
        $items = [];

        foreach (self::SCENARIOS as $key => $definition) {
            $items[] = [
                'key' => $key,
                'title' => trans_message("one_c_exchange.runbook.{$key}.title"),
                'severity' => $definition['severity'],
                'signals' => $this->lines($key, 'signals', $definition['signals']),
                'prohelper_checks' => $this->lines($key, 'prohelper_checks', $definition['prohelper_checks']),
                'handoff_to_1c' => $this->lines($key, 'handoff_to_1c', $definition['handoff_to_1c']),
                'retry_allowed' => trans_message("one_c_exchange.runbook.{$key}.retry_allowed"),
                'manual_review' => trans_message("one_c_exchange.runbook.{$key}.manual_review"),
                'escalate_when' => trans_message("one_c_exchange.runbook.{$key}.escalate_when"),
            ];
        }

        return $items;
    }

    private function lines(string $scenario, string $section, array $keys): array
    {
        return array_map(
            static fn (string $key): string => trans_message("one_c_exchange.runbook.{$scenario}.{$section}.{$key}"),
            $keys
        );
    }
}
