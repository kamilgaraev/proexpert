<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use Throwable;

final class AssistantHttpRequestOptions
{
    public static function forContext(float $timeoutSeconds, AssistantRequestExecutionContext $context): array
    {
        $timeout = $context->remainingSeconds(max(0.1, $timeoutSeconds));

        return self::forCheckpoint($timeout, $context->assertCanContinue(...));
    }

    public static function forCheckpoint(float $timeoutSeconds, callable $checkpoint): array
    {
        $lastCheckpoint = 0;
        $abortException = null;
        $progress = static function (...$transfer) use ($checkpoint, &$lastCheckpoint, &$abortException): int {
            if ($abortException !== null) {
                return 1;
            }

            $now = hrtime(true);
            if ($lastCheckpoint !== 0 && $now - $lastCheckpoint < 1_000_000_000) {
                return 0;
            }

            $lastCheckpoint = $now;
            try {
                $checkpoint();
            } catch (Throwable $exception) {
                $abortException = $exception;

                return 1;
            }

            return 0;
        };

        $options = [
            'timeout' => $timeoutSeconds,
            'connect_timeout' => min(5.0, $timeoutSeconds),
            'progress' => static function (...$transfer) use ($progress, &$abortException): void {
                if ($progress(...$transfer) !== 0 && $abortException !== null) {
                    throw $abortException;
                }
            },
        ];

        if (defined('CURLOPT_NOPROGRESS') && defined('CURLOPT_XFERINFOFUNCTION')) {
            $options['curl'] = [
                CURLOPT_NOPROGRESS => false,
                CURLOPT_XFERINFOFUNCTION => static function (...$transfer) use ($progress): int {
                    return $progress(...$transfer);
                },
            ];
        } elseif (defined('CURLOPT_NOPROGRESS') && defined('CURLOPT_PROGRESSFUNCTION')) {
            $options['curl'] = [
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => static function (...$transfer) use ($progress): int {
                    return $progress(...$transfer);
                },
            ];
        }

        return $options;
    }
}
